package nicetrycapture

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Origins{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_origins", parseOriginsCaddyfile)
}

// Origins is the nicetry_origins handler: it looks up where each domain's traffic goes (polled from
// NiceTryBot) and hands that to the Caddyfile's reverse_proxy as vars:
//
//	nicetry_origin_scheme   https or http (unset when the domain has no origin, so the site 503s)
//	nicetry_origin_upstream host:port to dial
//	nicetry_origin_sni      TLS server name for an https origin
//	nicetry_origin_host     Host header the origin receives
//	nicetry_origin_fwd      header that carries the visitor's host (X-Forwarded-Host when none chosen)
//	nicetry_origin_site     the host the origin is told the visitor asked for: the domain itself, also
//	                        on its preview hostname (the request's host when the app doesn't say)
//	nicetry_origin_secret   the domain's origin secret
//	nicetry_origin_hsts     Strict-Transport-Security value the domain chose (empty for none)
//	nicetry_origin_cache_assets  "1" when browsers may cache /img, /css and /js (empty otherwise)
//
// Any further headers the domain wants the visitor's host in (an origin moving from another proxy may
// read more than one) are set on the request here, overwriting whatever the visitor sent in them.
//
// On a preview hostname (shop-example-com.preview.example for shop.example.com) the origin is told the
// visitor asked for the site, so it serves exactly what visitors will get once DNS moves, and redirects
// it sends to the site are pointed back at the preview so the visitor stays on it.
//
// A host missing from the last poll (a site an origin vouched for moments ago) is looked up once at the
// resolve endpoint, if one is set. It also holds the origin to the domain's timeout for the response to
// start, answering 504 when it doesn't.
type Origins struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Resolve  string         `json:"resolve,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	origins  *originMap
	resolver *originResolver
}

// originRoute is one domain's route to its origin.
type originRoute struct {
	Scheme     string `json:"scheme"`
	Upstream   string `json:"upstream"`
	ServerName string `json:"server_name"`
	HostHeader string `json:"host_header"`
	// The host the origin is told the visitor asked for; differs from the request's host on a preview.
	SiteHost            string `json:"site_host"`
	ForwardedHostHeader string `json:"forwarded_host_header"`
	// More headers that carry the visitor's host, after ForwardedHostHeader.
	ExtraForwardedHostHeaders []string `json:"extra_forwarded_host_headers"`
	Secret                    string   `json:"secret"`
	Timeout                   int      `json:"timeout"`
	HSTS                      string   `json:"hsts"`
	CacheAssets               bool     `json:"cache_assets"`
}

// CaddyModule returns the Caddy module information.
func (Origins) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_origins",
		New: func() caddy.Module { return new(Origins) },
	}
}

// Provision starts (or joins) the poller for the endpoint.
func (o *Origins) Provision(caddy.Context) error {
	if o.Interval <= 0 {
		o.Interval = caddy.Duration(30 * time.Second)
	}
	o.origins = sharedOrigins(o.Endpoint, o.Token, time.Duration(o.Interval))
	if o.Resolve != "" {
		o.resolver = sharedResolver(o.Resolve, o.Token)
	}
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (o *Origins) Validate() error {
	if o.Endpoint == "" || o.Token == "" {
		return fmt.Errorf("nicetry_origins needs an endpoint and a token")
	}
	return nil
}

func (o *Origins) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	host := strings.ToLower(hostOnly(r.Host))
	route, ok := (*o.origins.current.Load())[host]
	if !ok && o.resolver != nil {
		route, ok = o.resolver.lookup(host)
	}
	if !ok {
		return next.ServeHTTP(w, r)
	}

	forwarded := route.ForwardedHostHeader
	if forwarded == "" {
		forwarded = "X-Forwarded-Host"
	}
	site := route.SiteHost
	if site == "" {
		site = hostOnly(r.Host)
	}
	ctx := r.Context()
	caddyhttp.SetVar(ctx, "nicetry_origin_scheme", route.Scheme)
	caddyhttp.SetVar(ctx, "nicetry_origin_upstream", route.Upstream)
	caddyhttp.SetVar(ctx, "nicetry_origin_sni", route.ServerName)
	caddyhttp.SetVar(ctx, "nicetry_origin_host", route.HostHeader)
	caddyhttp.SetVar(ctx, "nicetry_origin_fwd", forwarded)
	caddyhttp.SetVar(ctx, "nicetry_origin_site", site)
	caddyhttp.SetVar(ctx, "nicetry_origin_secret", route.Secret)
	caddyhttp.SetVar(ctx, "nicetry_origin_hsts", route.HSTS)
	cacheAssets := ""
	if route.CacheAssets {
		cacheAssets = "1"
	}
	caddyhttp.SetVar(ctx, "nicetry_origin_cache_assets", cacheAssets)
	for _, name := range route.ExtraForwardedHostHeaders {
		if name = strings.TrimSpace(name); name != "" {
			r.Header.Set(name, site)
		}
	}
	if !strings.EqualFold(site, host) {
		w = &previewWriter{ResponseWriter: w, site: site, preview: r.Host}
	}

	if route.Timeout <= 0 {
		return next.ServeHTTP(w, r)
	}

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	tw := &timeoutWriter{ResponseWriter: w}
	timer := time.AfterFunc(time.Duration(route.Timeout)*time.Second, func() {
		if tw.expire() {
			cancel()
		}
	})
	defer timer.Stop()

	err := next.ServeHTTP(tw, r.WithContext(ctx))
	if tw.expired() && (err != nil || tw.swallowed) {
		// An error, not a written status, so the edge's error page (dressed as nginx) answers.
		return caddyhttp.Error(http.StatusGatewayTimeout, fmt.Errorf("origin did not answer within %ds", route.Timeout))
	}
	return err
}

// timeoutWriter notes when the response starts, so the timeout only cuts off an origin that hasn't
// answered yet, never a long download that's already flowing.
type timeoutWriter struct {
	http.ResponseWriter

	mu        sync.Mutex
	started   bool
	timedOut  bool
	swallowed bool
}

// expire marks the request as timed out unless the response already started.
func (t *timeoutWriter) expire() bool {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.started {
		return false
	}
	t.timedOut = true
	return true
}

func (t *timeoutWriter) expired() bool {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.timedOut
}

func (t *timeoutWriter) WriteHeader(code int) {
	t.mu.Lock()
	t.started = true
	// reverse_proxy records a canceled request as 499 (the visitor went away). Here the origin was too
	// slow, so hold it back and let ServeHTTP answer 504 instead.
	if t.timedOut && code == 499 {
		t.swallowed = true
		t.mu.Unlock()
		return
	}
	t.mu.Unlock()
	t.ResponseWriter.WriteHeader(code)
}

func (t *timeoutWriter) Write(p []byte) (int, error) {
	t.mu.Lock()
	started := t.started
	t.mu.Unlock()
	if !started {
		t.WriteHeader(http.StatusOK)
	}
	return t.ResponseWriter.Write(p)
}

// Unwrap lets http.ResponseController reach the real writer to flush or hijack (WebSockets).
func (t *timeoutWriter) Unwrap() http.ResponseWriter { return t.ResponseWriter }

// previewWriter points redirects to the site back at the preview hostname the visitor is on, so trying
// a site on its preview doesn't bounce them to the site itself (which may not reach the edge yet).
type previewWriter struct {
	http.ResponseWriter

	site    string // the host the origin was told the visitor asked for
	preview string // the host the visitor asked for, port and all
}

func (p *previewWriter) WriteHeader(code int) {
	if location := p.Header().Get("Location"); location != "" {
		if u, err := url.Parse(location); err == nil && strings.EqualFold(u.Hostname(), p.site) {
			u.Host = p.preview
			p.Header().Set("Location", u.String())
		}
	}
	p.ResponseWriter.WriteHeader(code)
}

// Unwrap lets http.ResponseController reach the real writer to flush or hijack (WebSockets).
func (p *previewWriter) Unwrap() http.ResponseWriter { return p.ResponseWriter }

type originMap struct {
	current atomic.Pointer[map[string]originRoute]
}

var (
	originMapsMu sync.Mutex
	originMaps   = map[string]*originMap{}
)

// sharedOrigins returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedOrigins(endpoint, token string, interval time.Duration) *originMap {
	originMapsMu.Lock()
	defer originMapsMu.Unlock()

	key := endpoint + "\x00" + token
	if m, ok := originMaps[key]; ok {
		return m
	}

	m := &originMap{}
	m.current.Store(&map[string]originRoute{})
	originMaps[key] = m
	go m.poll(endpoint, token, interval)
	return m
}

func (m *originMap) poll(endpoint, token string, interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	logger := caddy.Log().Named("nicetry_origins")
	etag := ""

	for {
		origins, newEtag, err := fetchOrigins(client, endpoint, token, etag)
		switch {
		case err != nil:
			logger.Warn("keeping the last origins", zap.Error(err))
		case origins != nil:
			m.current.Store(&origins)
			etag = newEtag
			logger.Info("origins updated", zap.Int("sites", len(origins)))
		}
		time.Sleep(interval)
	}
}

var headerName = regexp.MustCompile(`^[A-Za-z0-9-]+$`)

// fetchOrigins asks for the current origins. An unchanged list (304) comes back as nil, nil.
func fetchOrigins(client *http.Client, endpoint, token, etag string) (map[string]originRoute, string, error) {
	req, err := http.NewRequest(http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, "", err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}

	resp, err := client.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotModified {
		return nil, etag, nil
	}
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("origins returned %d", resp.StatusCode)
	}

	var payload struct {
		Origins []struct {
			Host string `json:"host"`
			originRoute
		} `json:"origins"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 16<<20)).Decode(&payload); err != nil {
		return nil, "", err
	}

	origins := make(map[string]originRoute, len(payload.Origins))
	for _, site := range payload.Origins {
		if site.Host != "" && validOrigin(site.originRoute) {
			origins[strings.ToLower(site.Host)] = site.originRoute
		}
	}
	return origins, resp.Header.Get("ETag"), nil
}

// validOrigin drops anything that could break the proxy or smuggle headers. The app checks too, but
// never trust the wire.
func validOrigin(o originRoute) bool {
	if o.Scheme != "https" && o.Scheme != "http" {
		return false
	}
	if _, port, err := net.SplitHostPort(o.Upstream); err != nil || port == "" {
		return false
	}
	if o.ForwardedHostHeader != "" && !headerName.MatchString(o.ForwardedHostHeader) {
		return false
	}
	for _, v := range []string{o.Upstream, o.ServerName, o.HostHeader, o.SiteHost, o.Secret} {
		if strings.ContainsAny(v, "\r\n{}") {
			return false
		}
	}
	return o.HostHeader != ""
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_origins {
//	    endpoint <url>
//	    resolve  <url>
//	    token    <token>
//	    interval <duration>
//	}
func (o *Origins) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			o.Endpoint = raw
		case "resolve":
			o.Resolve = raw
		case "token":
			o.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			o.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_origins option %q", key)
		}
	}
	return nil
}

func parseOriginsCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	o := new(Origins)
	err := o.UnmarshalCaddyfile(h.Dispenser)
	return o, err
}

var (
	_ caddy.Provisioner           = (*Origins)(nil)
	_ caddy.Validator             = (*Origins)(nil)
	_ caddyhttp.MiddlewareHandler = (*Origins)(nil)
	_ caddyfile.Unmarshaler       = (*Origins)(nil)
)
