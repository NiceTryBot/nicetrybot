package nicetrycapture

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Gym{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_gym", parseGymCaddyfile)
}

// gymCookies are the only cookies forwarded to NiceTryBot; the site's own cookies never leave the edge.
var gymCookies = map[string]bool{"_q_pass": true, "_q_session": true}

// gymHeaders are the only headers of the gym's answer passed on to the visitor.
var gymHeaders = []string{"Content-Type", "Cache-Control", "Set-Cookie", "Location", "X-Robots-Tag", "Retry-After"}

// Gym is the nicetry_gym handler. For requests on a gym trigger (host + path glob, polled from
// NiceTryBot), it asks the check endpoint whether the visitor trains first. 204 lets the request
// through; any other answer is the gym itself, served in place so the address bar never changes.
// If NiceTryBot can't be reached or errors, the request goes through (fail open). When the check
// answers 204 with X-NiceTry-Report, the edge tells the report endpoint the origin's status once it
// has answered (e.g. so a sign-in that reaches the dashboard lifts the visitor's verdict at once).
type Gym struct {
	Triggers string         `json:"triggers,omitempty"`
	Check    string         `json:"check,omitempty"`
	Report   string         `json:"report,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`
	Timeout  caddy.Duration `json:"timeout,omitempty"`
	// Geo is the folder holding the DB-IP Lite City and ASN files, so rules can see where a visitor
	// comes from (see geo.go). Without it, place and network are unknown at the edge.
	Geo string `json:"geo,omitempty"`

	targets *targetSet
	client  *http.Client
	logger  *zap.Logger
}

// CaddyModule returns the Caddy module information.
func (Gym) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_gym",
		New: func() caddy.Module { return new(Gym) },
	}
}

// Provision starts (or joins) the trigger poller.
func (g *Gym) Provision(ctx caddy.Context) error {
	if g.Interval <= 0 {
		g.Interval = caddy.Duration(30 * time.Second)
	}
	if g.Timeout <= 0 {
		g.Timeout = caddy.Duration(3 * time.Second)
	}
	g.logger = ctx.Logger()
	g.client = &http.Client{
		Timeout: time.Duration(g.Timeout),
		// The gym's own redirects are for the visitor, not for us to follow.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	g.targets = sharedTargets(g.Triggers, g.Token, time.Duration(g.Interval), "triggers", true)
	if g.Geo != "" {
		useGeo(g.Geo)
	}
	return nil
}

// Validate checks the handler has both endpoints and a token.
func (g *Gym) Validate() error {
	if g.Triggers == "" || g.Check == "" || g.Token == "" {
		return fmt.Errorf("nicetry_gym needs triggers, check and token")
	}
	return nil
}

func (g *Gym) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	// The gym's own requests (/_q/…) and the edge diagnostic are never sent to the gym. The path is
	// cleaned first: /_q/../login reaches the origin as /login.
	if cleaned := cleanedPath(r); strings.HasPrefix(cleaned, "/_q/") || strings.HasPrefix(cleaned, "/__nicetry/") {
		return next.ServeHTTP(w, r)
	}
	trigger, matched := g.targets.matchOn(r)
	if trigger == nil {
		return next.ServeHTTP(w, r)
	}
	if matched != r.URL.Path {
		// NiceTryBot matches routines to the path too: tell it the one the trigger matched.
		caddyhttp.SetVar(r.Context(), gymPathVar, matched)
	}

	resp, err := g.ask(r)
	if err != nil {
		g.logger.Warn("gym check failed, letting the request through", zap.Error(err))
		return next.ServeHTTP(w, r)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNoContent || resp.StatusCode >= 500 {
		// A pass can come with cookies (e.g. clearing the session); keep them on the origin's answer.
		for _, cookie := range resp.Header.Values("Set-Cookie") {
			w.Header().Add("Set-Cookie", cookie)
		}
		if resp.StatusCode != http.StatusNoContent || resp.Header.Get("X-NiceTry-Report") == "" || g.Report == "" {
			return next.ServeHTTP(w, r)
		}

		described := g.describe(r)
		rec := &statusRecorder{ResponseWriter: w}
		err := next.ServeHTTP(rec, r)
		go g.report(described, rec.status())
		return err
	}

	for _, name := range gymHeaders {
		for _, value := range resp.Header.Values(name) {
			w.Header().Add(name, value)
		}
	}
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("action", "diverted"))
	}
	w.WriteHeader(resp.StatusCode)
	_, err = io.Copy(w, io.LimitReader(resp.Body, 4<<20))
	return err
}

// gymPathVar holds the judged path a request's trigger matched, when it isn't the path as sent
// (/index.php/login for a trigger on /login*).
const gymPathVar = "nicetry_gym_path"

// describe is what NiceTryBot hears about a visitor's request: who, what, and the gym's own cookies.
func (g *Gym) describe(r *http.Request) http.Header {
	h := http.Header{}
	h.Set("Authorization", "Bearer "+g.Token)
	h.Set("User-Agent", r.UserAgent())
	// Enough for NiceTryBot to tell a page load from a script's request (Livewire, fetch) and send the
	// latter back to the page it came from, the language rules may ask about, and the client hints a
	// gym session keeps from the start (a scanner served the gym in place may never send another).
	for _, name := range []string{"Accept", "Accept-Language", "Referer", "X-Livewire", "X-Requested-With", "Sec-CH-UA", "Sec-CH-UA-Platform"} {
		if value := r.Header.Get(name); value != "" {
			h.Set(name, value)
		}
	}
	h.Set("X-NiceTry-Host", strings.ToLower(hostOnly(r.Host)))
	uri := r.URL.RequestURI()
	if matched, ok := caddyhttp.GetVar(r.Context(), gymPathVar).(string); ok && matched != "" {
		uri = uriFor(r, matched)
	}
	h.Set("X-NiceTry-Uri", uri)
	h.Set("X-NiceTry-Method", r.Method)
	h.Set("X-NiceTry-Client-Ip", requestIP(r))
	if repl, ok := r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer); ok {
		h.Set("X-NiceTry-JA4", repl.ReplaceAll("{tls.ja4}", ""))
	}
	describeClient(h, r)
	var cookies []string
	for _, cookie := range r.Cookies() {
		if gymCookies[cookie.Name] {
			cookies = append(cookies, cookie.String())
		}
	}
	if len(cookies) > 0 {
		h.Set("Cookie", strings.Join(cookies, "; "))
	}
	return h
}

// gymRulesFeature is what this edge announces (X-NiceTry-Features) to NiceTryBot: it judges single-
// request rules, and describes the client to the gym check.
const gymRulesFeature = "gym-rules"

// describeClient adds what rules may ask about the client to the gym check: its edge tag, where it
// comes from (when the IP database is open), and the rules the request matched at the edge. Place and
// network names are URL-encoded, since they aren't always ASCII.
func describeClient(h http.Header, r *http.Request) {
	h.Set("X-NiceTry-Features", gymRulesFeature)
	if tag, ok := caddyhttp.GetVar(r.Context(), "bot_tag").(string); ok && tag != "" {
		h.Set("X-NiceTry-Tag", tag)
	}
	if geo := sharedGeo(); geo != nil {
		if place, ok := geo.lookup(requestIP(r)); ok {
			for name, value := range map[string]string{"X-NiceTry-Country": place.country, "X-NiceTry-Region": place.region, "X-NiceTry-City": place.city, "X-NiceTry-Network": place.network} {
				if value != "" {
					h.Set(name, url.PathEscape(value))
				}
			}
			if place.asn != 0 {
				h.Set("X-NiceTry-ASN", strconv.FormatUint(uint64(place.asn), 10))
			}
		}
	}
	if ids := rememberedRules(r); len(ids) > 0 {
		parts := make([]string, len(ids))
		for i, id := range ids {
			parts[i] = strconv.Itoa(id)
		}
		h.Set("X-NiceTry-Rules", strings.Join(parts, ","))
	}
}

// report tells NiceTryBot how the origin answered a request the check let through provisionally.
func (g *Gym) report(described http.Header, status int) {
	ctx, cancel := context.WithTimeout(context.Background(), time.Duration(g.Timeout))
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, g.Report, nil)
	if err != nil {
		return
	}
	req.Header = described.Clone()
	req.Header.Set("X-NiceTry-Status", strconv.Itoa(status))

	resp, err := g.client.Do(req)
	if err != nil {
		g.logger.Warn("gym report failed", zap.Error(err))
		return
	}
	_ = resp.Body.Close()
}

// statusRecorder notes the status the origin answered with while passing everything through.
type statusRecorder struct {
	http.ResponseWriter
	code int
}

func (s *statusRecorder) WriteHeader(code int) {
	if s.code == 0 {
		s.code = code
	}
	s.ResponseWriter.WriteHeader(code)
}

func (s *statusRecorder) Write(p []byte) (int, error) {
	if s.code == 0 {
		s.code = http.StatusOK
	}
	return s.ResponseWriter.Write(p)
}

func (s *statusRecorder) Unwrap() http.ResponseWriter { return s.ResponseWriter }

func (s *statusRecorder) status() int {
	if s.code == 0 {
		return http.StatusOK
	}
	return s.code
}

// ask describes the visitor to NiceTryBot's gym check.
func (g *Gym) ask(r *http.Request) (*http.Response, error) {
	ctx, cancel := context.WithTimeout(r.Context(), time.Duration(g.Timeout))
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, g.Check, nil)
	if err != nil {
		cancel()
		return nil, err
	}
	req.Header = g.describe(r)

	resp, err := g.client.Do(req)
	if err != nil {
		cancel()
		return nil, err
	}
	resp.Body = cancelOnClose{resp.Body, cancel}
	return resp, nil
}

type cancelOnClose struct {
	io.ReadCloser
	cancel context.CancelFunc
}

func (c cancelOnClose) Close() error {
	err := c.ReadCloser.Close()
	c.cancel()
	return err
}

func hostOnly(host string) string {
	if i := strings.LastIndex(host, ":"); i > -1 && !strings.Contains(host[i:], "]") {
		return host[:i]
	}
	return host
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_gym {
//	    triggers <url>
//	    check    <url>
//	    report   <url>
//	    token    <token>
//	    interval <duration>
//	    timeout  <duration>
//	    geo      <folder with dbip-city-lite.mmdb and dbip-asn-lite.mmdb>
//	}
func (g *Gym) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "triggers":
			g.Triggers = raw
		case "check":
			g.Check = raw
		case "report":
			g.Report = raw
		case "token":
			g.Token = raw
		case "geo":
			g.Geo = raw
		case "interval", "timeout":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("%s: %v", key, err)
			}
			if key == "interval" {
				g.Interval = caddy.Duration(dur)
			} else {
				g.Timeout = caddy.Duration(dur)
			}
		default:
			return d.Errf("unknown nicetry_gym option %q", key)
		}
	}
	return nil
}

func parseGymCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	g := new(Gym)
	err := g.UnmarshalCaddyfile(h.Dispenser)
	return g, err
}

var (
	_ caddy.Provisioner           = (*Gym)(nil)
	_ caddy.Validator             = (*Gym)(nil)
	_ caddyhttp.MiddlewareHandler = (*Gym)(nil)
	_ caddyfile.Unmarshaler       = (*Gym)(nil)
)
