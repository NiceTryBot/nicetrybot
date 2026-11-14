package nicetrycapture

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"path"
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
	caddy.RegisterModule(Assets{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_assets", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		a := new(Assets)
		return a, a.UnmarshalCaddyfile(h.Dispenser)
	})
}

// assetsSecretHeader carries the secret the CDN sends with every pull. A visitor's own copy never
// reaches an origin.
const assetsSecretHeader = "X-NiceTry-Assets"

// assetsBotTag marks, in the access log, a visitor's request for a file the edge sent to the CDN.
const assetsBotTag = "s3_assets"

const assetsMaxPayload = 16 << 20

// Assets is the nicetry_assets handler, for the Offload Assets add-on: sites' static files (images,
// stylesheets, scripts, fonts, video) are served by the installation's pull CDN (CloudFront, Bunny,
// Cloudflare or any other), so the edge serves pages and nothing else. Each site's files live under its
// CDN base URL: host style, <key>.<asset domain> on a wildcard domain, or path style,
// cdn.<asset domain>/<key>, every site on one hostname. It polls NiceTryBot for the asset domain, the
// pull host, a hash of the pull secret, the file types and every site with a key (and its base URL), and
// does two things:
//
//   - A pull: the CDN asks the pull host for /<key>/<path> with the secret, in either style. A CDN that
//     can't set the Host it sends to the origin (Cloudflare below Enterprise) asks under its own hostname
//     instead, so the feed can name pull aliases: a request to one is treated exactly as one to the pull
//     host (the same checks, the same 404 for anything else). The request
//     becomes one for <path> on the key's site, so the rest of the edge routes it to that site's origin
//     as usual (a locked-down site stays locked); it skips the tarpit, the gym and the verdict, and isn't
//     logged (it's the CDN, not a visitor). Only GET and HEAD for a file type the CDN carries, on a
//     listed key, with the right secret: anything else on the pull host is a 404, so the CDN can't be
//     used to fetch a page or reach anywhere the list doesn't name. Every pull's answer carries
//     Access-Control-Allow-Origin: *, cached with the file, so pages on the sites' own hostnames can load
//     fonts and module scripts from the CDN. (A CDN's own CORS policy alone isn't enough: CloudFront's
//     leaves the header off when the browser sends a Priority header, as Chrome always does.) An SVG's
//     answer also carries Content-Security-Policy: sandbox, so one opened as a document can't run script
//     on the CDN's hostname, which path style shares between every site.
//   - A straggler: a visitor asks a site that's on for one of its files directly (a URL a script built,
//     a stylesheet's absolute url()). It gets a 302 to the same path under the site's CDN base URL. Pages are rewritten to
//     point at the CDN in the first place (nicetry_rewrite), so these are few. A classic script or
//     stylesheet (Sec-Fetch-Dest script or style, Sec-Fetch-Mode no-cors) is served here instead: sent
//     to another origin it would come back opaque, and a Subresource Integrity check on it would fail.
//
// It belongs after nicetry_allow and before nicetry_tarpit. If NiceTryBot can't be reached the last
// list stays; a 404 (the add-on turned off) empties it.
type Assets struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	feed *assetsFeed
}

// CaddyModule returns the Caddy module information.
func (Assets) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_assets",
		New: func() caddy.Module { return new(Assets) },
	}
}

// Provision starts (or joins) the assets poller.
func (a *Assets) Provision(caddy.Context) error {
	if a.Interval <= 0 {
		a.Interval = caddy.Duration(10 * time.Second)
	}
	a.feed = sharedAssets(a.Endpoint, a.Token, time.Duration(a.Interval))
	return nil
}

// Validate checks the handler has an endpoint and a token.
func (a *Assets) Validate() error {
	if a.Endpoint == "" || a.Token == "" {
		return fmt.Errorf("nicetry_assets needs an endpoint and a token")
	}
	return nil
}

func (a *Assets) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	cfg := a.feed.load()
	host := strings.ToLower(hostOnly(r.Host))

	if cfg.isPullHost(host) {
		return a.servePull(w, r, next, cfg)
	}
	r.Header.Del(assetsSecretHeader)

	site := cfg.byHost[host]
	if site == nil || !site.on || !assetsMethod(r.Method) || strings.HasPrefix(r.URL.Path, "/_q/") ||
		!cfg.isAsset(r.URL.Path) || cfg.skipped(r.URL.Path) || assetsMayBePinned(r) {
		return next.ServeHTTP(w, r)
	}

	location := site.to
	if location == "" {
		location = "https://" + site.key + "." + cfg.domain
	}
	location += r.URL.EscapedPath()
	if r.URL.RawQuery != "" {
		location += "?" + r.URL.RawQuery
	}
	logAssets(r)
	w.Header().Set("Location", location)
	w.Header().Set("Cache-Control", "public, max-age=3600")
	w.WriteHeader(http.StatusFound)
	return nil
}

// servePull turns the CDN's /<key>/<path> into a request for <path> on the key's site, or answers 404.
func (a *Assets) servePull(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler, cfg *assetsConfig) error {
	caddyhttp.SetVar(r.Context(), caddyhttp.LogSkipVar, true)

	secret := r.Header.Get(assetsSecretHeader)
	key, rest, _ := strings.Cut(strings.TrimPrefix(r.URL.Path, "/"), "/")
	rest = "/" + rest
	site := cfg.byKey[key]

	if !cfg.secretMatches(secret) || site == nil || !assetsMethod(r.Method) ||
		strings.HasPrefix(rest, "/_q/") || !cfg.isAsset(rest) || cfg.skipped(rest) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.WriteHeader(http.StatusNotFound)
		_, err := w.Write([]byte("Not Found\n"))
		return err
	}

	r.Host = site.site
	r.URL.Path = rest
	if r.URL.RawPath != "" {
		if _, rawRest, ok := strings.Cut(strings.TrimPrefix(r.URL.RawPath, "/"), "/"); ok {
			r.URL.RawPath = "/" + rawRest
		} else {
			r.URL.RawPath = ""
		}
	}
	r.RequestURI = r.URL.RequestURI()
	r.Header.Del(assetsSecretHeader)
	r.Header.Del("Cookie")
	r.Header.Del("Authorization")
	caddyhttp.SetVar(r.Context(), nicetryAllowedVar, true)
	return next.ServeHTTP(&assetsCORSWriter{ResponseWriter: w, svg: assetsIsSVG(rest)}, r)
}

// assetsCORSWriter lets any page use a pulled file: Access-Control-Allow-Origin is * whatever the origin
// sent (the files are public, served to anyone through the CDN), and nobody's credentials are involved.
// An SVG (by its extension, or by the type the origin answers with) also gets Content-Security-Policy:
// sandbox, cached with it: an <img> ignores it, but opened as a document on the CDN's hostname (shared by
// every site in path style) the SVG can't run script there.
type assetsCORSWriter struct {
	http.ResponseWriter
	svg   bool
	wrote bool
}

// Unwrap lets http.ResponseController reach the writer underneath (for flushing and the like).
func (w *assetsCORSWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

func (w *assetsCORSWriter) WriteHeader(status int) {
	if !w.wrote && status >= 200 {
		w.wrote = true
		h := w.Header()
		h.Set("Access-Control-Allow-Origin", "*")
		h.Del("Access-Control-Allow-Credentials")
		if w.svg || assetsSVGType(h.Get("Content-Type")) {
			h.Add("Content-Security-Policy", "sandbox")
		}
	}
	w.ResponseWriter.WriteHeader(status)
}

func (w *assetsCORSWriter) Write(p []byte) (int, error) {
	if !w.wrote {
		w.WriteHeader(http.StatusOK)
	}
	return w.ResponseWriter.Write(p)
}

// assetsIsSVG reports whether a path names an SVG image, plain or gzipped.
func assetsIsSVG(p string) bool {
	ext := strings.ToLower(path.Ext(p))
	return ext == ".svg" || ext == ".svgz"
}

// assetsSVGType reports whether a Content-Type is SVG's.
func assetsSVGType(contentType string) bool {
	mediaType, _, _ := strings.Cut(contentType, ";")
	return strings.EqualFold(strings.TrimSpace(mediaType), "image/svg+xml")
}

// assetsMayBePinned reports whether the browser is loading a classic script or a stylesheet without CORS,
// which an integrity attribute may pin: redirected to the CDN, the hash couldn't be checked.
func assetsMayBePinned(r *http.Request) bool {
	dest := r.Header.Get("Sec-Fetch-Dest")
	return (dest == "script" || dest == "style") && r.Header.Get("Sec-Fetch-Mode") == "no-cors"
}

func assetsMethod(method string) bool {
	return method == http.MethodGet || method == http.MethodHead
}

// logAssets records in the access log that the edge sent a visitor to the CDN.
func logAssets(r *http.Request) {
	caddyhttp.SetVar(r.Context(), "bot_tag", assetsBotTag)
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("action", "passed"))
	}
}

// assetsConfig is the feed as the edge uses it.
type assetsConfig struct {
	domain      string
	pullHost    string
	pullAliases map[string]bool // other hostnames the CDN pulls under (its own, when it can't set Host)
	secretHash  []byte
	extensions  map[string]bool
	skipPaths   []string
	byKey       map[string]*assetSite
	byHost      map[string]*assetSite
}

type assetSite struct {
	key  string
	site string
	to   string // the site's CDN base URL from the feed, no trailing slash; empty for https://<key>.<domain>
	on   bool
}

var emptyAssets = &assetsConfig{extensions: map[string]bool{}, byKey: map[string]*assetSite{}, byHost: map[string]*assetSite{}}

// isAsset reports whether a path names a file type the CDN carries, by its extension, and isn't a
// script that has to come from the site itself (a service or web worker).
func (c *assetsConfig) isAsset(p string) bool {
	ext := strings.ToLower(strings.TrimPrefix(path.Ext(p), "."))
	return ext != "" && c.extensions[ext] && !assetMustStayHome(p)
}

// isPullHost reports whether a request's host is the pull host or one of its aliases.
func (c *assetsConfig) isPullHost(host string) bool {
	return host != "" && (host == c.pullHost || c.pullAliases[host])
}

// skipped reports whether a path is under one of the paths left alone.
func (c *assetsConfig) skipped(p string) bool {
	return pathUnder(p, c.skipPaths)
}

// secretMatches reports whether a header holds the pull secret, comparing hashes in constant time.
func (c *assetsConfig) secretMatches(secret string) bool {
	if secret == "" || len(c.secretHash) != sha256.Size {
		return false
	}
	sum := sha256.Sum256([]byte(secret))
	return subtle.ConstantTimeCompare(sum[:], c.secretHash) == 1
}

type assetsFeed struct {
	current atomic.Pointer[assetsConfig]

	endpoint, token string
	etag            string
}

var (
	assetsFeedsMu sync.Mutex
	assetsFeeds   = map[string]*assetsFeed{}
)

// sharedAssets returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedAssets(endpoint, token string, interval time.Duration) *assetsFeed {
	assetsFeedsMu.Lock()
	defer assetsFeedsMu.Unlock()

	key := endpoint + "\x00" + token
	if f, ok := assetsFeeds[key]; ok {
		return f
	}

	f := &assetsFeed{endpoint: endpoint, token: token}
	f.current.Store(emptyAssets)
	assetsFeeds[key] = f
	go f.poll(interval)
	return f
}

func (f *assetsFeed) load() *assetsConfig {
	return f.current.Load()
}

// restore starts the list from its last good copy.
func (f *assetsFeed) restore(store *lastGood) bool {
	cfg, ok := restoreList(store, "assets", parseAssets)
	if ok {
		f.current.Store(cfg)
	}
	return ok
}

func (f *assetsFeed) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	f.restore(sharedLastGood())
	for {
		if err := f.refresh(client); err != nil {
			caddy.Log().Named("nicetry_assets").Warn("keeping the last assets list", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks NiceTryBot for the list. A 404 means the add-on is off: no CDN. Anything else but a good
// answer leaves the last list.
func (f *assetsFeed) refresh(client *http.Client) error {
	req, err := http.NewRequest(http.MethodGet, f.endpoint, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+f.token)
	req.Header.Set("Accept", "application/json")
	if f.etag != "" {
		req.Header.Set("If-None-Match", f.etag)
	}

	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusNotModified:
		return nil
	case http.StatusNotFound:
		f.current.Store(emptyAssets)
		f.etag = ""
		return nil
	case http.StatusOK:
	default:
		return fmt.Errorf("assets feed returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, assetsMaxPayload+1))
	if err != nil {
		return err
	}
	if len(raw) > assetsMaxPayload {
		return fmt.Errorf("assets answer is over %d bytes", assetsMaxPayload)
	}

	cfg, err := parseAssets(raw)
	if err != nil {
		return err
	}
	f.current.Store(cfg)
	f.etag = resp.Header.Get("ETag")
	sharedLastGood().save("assets", raw)
	return nil
}

// parseAssets turns NiceTryBot's answer into the edge's list. The answer must name `assets`; one with no
// asset domain or pull host means no CDN. A site without a key is skipped. A site's `to` (its CDN base
// URL) is used for stragglers when it's a usable https URL (see assetBaseURL); otherwise, or without one
// (an app from before CDN providers), its files are at https://<key>.<domain>. `style` ("host" or
// "path") only describes the `to`s and changes nothing here. `pull_aliases` (optional) are other hostnames
// pulls arrive under, handled exactly as the pull host.
func parseAssets(raw []byte) (*assetsConfig, error) {
	var payload struct {
		Assets *struct {
			Domain       string   `json:"domain"`
			PullHost     string   `json:"pull_host"`
			PullAliases  []string `json:"pull_aliases"`
			SecretSHA256 string   `json:"secret_sha256"`
			Style        string   `json:"style"`
			Extensions   []string `json:"extensions"`
			SkipPaths    []string `json:"skip_paths"`
			Sites        []struct {
				Key   string   `json:"key"`
				Site  string   `json:"site"`
				To    string   `json:"to"`
				Hosts []string `json:"hosts"`
				On    bool     `json:"on"`
			} `json:"sites"`
		} `json:"assets"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Assets == nil {
		return nil, fmt.Errorf("assets answer has no assets")
	}
	in := payload.Assets
	if in.Domain == "" || in.PullHost == "" {
		return emptyAssets, nil
	}
	secretHash, err := hex.DecodeString(in.SecretSHA256)
	if err != nil || len(secretHash) != sha256.Size {
		return nil, fmt.Errorf("assets answer has no usable secret hash")
	}

	cfg := &assetsConfig{
		domain:      strings.ToLower(in.Domain),
		pullHost:    strings.ToLower(in.PullHost),
		pullAliases: map[string]bool{},
		secretHash:  secretHash,
		extensions:  map[string]bool{},
		skipPaths:   in.SkipPaths,
		byKey:       map[string]*assetSite{},
		byHost:      map[string]*assetSite{},
	}
	for _, alias := range in.PullAliases {
		if alias != "" {
			cfg.pullAliases[strings.ToLower(alias)] = true
		}
	}
	for _, ext := range in.Extensions {
		cfg.extensions[strings.ToLower(ext)] = true
	}
	for _, entry := range in.Sites {
		if entry.Key == "" || entry.Site == "" {
			continue
		}
		site := &assetSite{key: strings.ToLower(entry.Key), site: strings.ToLower(entry.Site), on: entry.On}
		if entry.To != "" {
			site.to, _ = assetBaseURL(entry.To)
		}
		cfg.byKey[site.key] = site
		for _, host := range entry.Hosts {
			cfg.byHost[strings.ToLower(host)] = site
		}
	}
	return cfg, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_assets {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (a *Assets) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			a.Endpoint = raw
		case "token":
			a.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			a.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_assets option %q", key)
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*Assets)(nil)
	_ caddy.Validator             = (*Assets)(nil)
	_ caddyhttp.MiddlewareHandler = (*Assets)(nil)
	_ caddyfile.Unmarshaler       = (*Assets)(nil)
)
