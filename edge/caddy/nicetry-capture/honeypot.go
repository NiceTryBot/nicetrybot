package nicetrycapture

import (
	"bytes"
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
	caddy.RegisterModule(Honeypot{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_honeypot", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		hp := new(Honeypot)
		return hp, hp.UnmarshalCaddyfile(h.Dispenser)
	})
}

const (
	honeypotMaxPayload = 16 << 20
	// A request body the honeypot can be sent too; a bigger one goes to the origin only, as always.
	honeypotMaxRequest = 1 << 20
	// The origin's own 404, kept to fall back on if the honeypot can't answer; a bigger one goes on as it is.
	honeypotMaxNotFound = 1 << 20
	// The most of a honeypot's answer the visitor gets.
	honeypotMaxAnswer      = 4 << 20
	honeypotDefaultTimeout = 30 * time.Second
	honeypotMaxTimeout     = 120 * time.Second
)

// Honeypot is the nicetry_honeypot handler. It polls NiceTryBot for the teams' honeypot traps (path
// patterns on some hostnames, each sending to one honeypot: an origin of the team's own, like a Galah
// server) and, when a request on one of those paths gets a 404 from the site's origin, answers it from
// the honeypot instead. A path the site really serves is never touched: only its 404s are.
//
// It sits right around reverse_proxy (inside encode, outside the rewrites), so everything before it has
// already run: fingerprints, the gym, tarpits, lockdowns, verdicts. A client on the allow list (White
// List, Nice Bots, so verified search engines too) always gets the site's own 404, and so do upgrades
// (WebSockets) and requests whose body is over 1 MiB.
//
// The origin's 404 is held, not sent: its headers stay apart from the response's until it's known
// whether it goes out. The honeypot gets the visitor's request (method, path, query, headers, body) with
// Host set to its own hostname, the visited site as X-Forwarded-Host, the visitor as X-Forwarded-For and
// the honeypot's secret as X-NiceTry-Origin-Secret. Its answer goes out with X-Robots-Tag: noindex, and
// without its own Server, Date and framing headers. If it can't answer in time, the visitor gets the
// site's own 404 after all. Each trapped request is logged as honeypot, honeypot_trap, honeypot_status,
// honeypot_ms and honeypot_outcome (served or failed). If NiceTryBot can't be reached the last list stays.
type Honeypot struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	traps  *honeypotMap
	client *http.Client
}

// CaddyModule returns the Caddy module information.
func (Honeypot) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_honeypot",
		New: func() caddy.Module { return new(Honeypot) },
	}
}

// Provision starts (or joins) the honeypot poller.
func (hp *Honeypot) Provision(caddy.Context) error {
	if hp.Interval <= 0 {
		hp.Interval = caddy.Duration(10 * time.Second)
	}
	hp.traps = sharedHoneypots(hp.Endpoint, hp.Token, time.Duration(hp.Interval))
	hp.client = sharedHoneypotClient()
	return nil
}

// Validate checks the handler has an endpoint and a token.
func (hp *Honeypot) Validate() error {
	if hp.Endpoint == "" || hp.Token == "" {
		return fmt.Errorf("nicetry_honeypot needs endpoint and token")
	}
	return nil
}

func (hp *Honeypot) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	traps := hp.traps.lookup(strings.ToLower(hostOnly(r.Host)))
	if len(traps) == 0 || r.Header.Get("Upgrade") != "" || isAllowed(r) {
		return next.ServeHTTP(w, r)
	}
	trap := matchHoneypotTrap(traps, judgedPaths(r))
	if trap == nil {
		return next.ServeHTTP(w, r)
	}
	body, ok := keepHoneypotBody(r)
	if !ok {
		return next.ServeHTTP(w, r)
	}

	hw := &honeypotWriter{under: w, header: w.Header().Clone()}
	err := next.ServeHTTP(hw, r)
	if !hw.caught {
		if !hw.passing {
			hw.keepHeaders()
		}
		return err
	}

	start := time.Now()
	answer, askErr := hp.ask(r, trap, body)
	took := time.Since(start)
	if askErr != nil {
		caddy.Log().Named("nicetry_honeypot").Debug("honeypot didn't answer, sending the site's 404",
			zap.Int("trap", trap.id), zap.String("host", r.Host), zap.Error(askErr))
		logHoneypot(r, trap, 0, took, "failed")
		return hw.sendNotFound()
	}
	logHoneypot(r, trap, answer.status, took, "served")
	return answer.writeTo(w, r.Method == http.MethodHead)
}

// honeypotAnswer is what a honeypot sent back, read whole.
type honeypotAnswer struct {
	status int
	header http.Header
	body   []byte
}

// ask sends the visitor's request to the trap's honeypot and reads its answer.
func (hp *Honeypot) ask(r *http.Request, trap *honeypotTrap, body []byte) (*honeypotAnswer, error) {
	ctx, cancel := context.WithTimeout(r.Context(), trap.timeout)
	defer cancel()

	target := *trap.origin
	target.Path, target.RawPath, target.RawQuery = r.URL.Path, r.URL.RawPath, r.URL.RawQuery
	req, err := http.NewRequestWithContext(ctx, r.Method, target.String(), bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header = honeypotRequestHeader(r.Header)
	req.Host = trap.origin.Host
	req.ContentLength = int64(len(body))
	req.Header.Set("X-Forwarded-Host", hostOnly(r.Host))
	req.Header.Set("X-Forwarded-For", requestIP(r))
	req.Header.Set("X-Forwarded-Proto", "https")
	req.Header.Set("X-NiceTry-Origin-Secret", trap.secret)

	resp, err := hp.client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, honeypotMaxAnswer))
	if err != nil {
		return nil, err
	}
	return &honeypotAnswer{status: resp.StatusCode, header: resp.Header, body: raw}, nil
}

// writeTo sends the honeypot's answer to the visitor. The response's headers so far are the ones set
// before the origin was asked (the edge's own), never the origin's 404's.
func (a *honeypotAnswer) writeTo(w http.ResponseWriter, head bool) error {
	h := w.Header()
	for name, values := range a.header {
		if honeypotDroppedAnswerHeaders[http.CanonicalHeaderKey(name)] {
			continue
		}
		h[name] = values
	}
	h.Set("X-Robots-Tag", "noindex, nofollow")
	status := a.status
	if status < 200 || status > 999 {
		status = http.StatusOK
	}
	w.WriteHeader(status)
	if head {
		return nil
	}
	_, err := w.Write(a.body)
	return err
}

// Hop-by-hop headers, and the ones the edge sets itself, never go from the visitor to the honeypot.
var honeypotDroppedRequestHeaders = map[string]bool{
	"Connection": true, "Keep-Alive": true, "Proxy-Authenticate": true, "Proxy-Authorization": true,
	"Proxy-Connection": true, "Te": true, "Trailer": true, "Transfer-Encoding": true, "Upgrade": true,
	"Content-Length": true, "Forwarded": true, "X-Real-Ip": true,
}

// honeypotRequestHeader is the visitor's headers as the honeypot gets them: no hop-by-hop ones, and none
// of the forwarding or X-NiceTry-* headers a visitor could send to pass as the edge.
func honeypotRequestHeader(visitor http.Header) http.Header {
	out := make(http.Header, len(visitor))
	for name, values := range visitor {
		key := http.CanonicalHeaderKey(name)
		if honeypotDroppedRequestHeaders[key] || strings.HasPrefix(key, "X-Forwarded-") || strings.HasPrefix(key, "X-Nicetry-") {
			continue
		}
		out[key] = append([]string(nil), values...)
	}
	return out
}

// What a honeypot answers with that never reaches the visitor: hop-by-hop and framing headers (Go sets
// the real ones, and encode compresses), and the Server and Date the edge sets itself, so a trapped page
// looks like the rest of the site.
var honeypotDroppedAnswerHeaders = map[string]bool{
	"Connection": true, "Keep-Alive": true, "Proxy-Authenticate": true, "Proxy-Connection": true,
	"Te": true, "Trailer": true, "Transfer-Encoding": true, "Upgrade": true,
	"Content-Length": true, "Content-Encoding": true, "Server": true, "Date": true, "Via": true, "Alt-Svc": true,
}

// keepHoneypotBody reads the request's body so both the origin and the honeypot can have it. A body over
// honeypotMaxRequest (said or found) isn't kept: false, and the origin still gets all of it.
func keepHoneypotBody(r *http.Request) ([]byte, bool) {
	if r.Body == nil || r.Body == http.NoBody {
		return nil, true
	}
	if r.ContentLength > honeypotMaxRequest {
		return nil, false
	}
	kept, err := io.ReadAll(io.LimitReader(r.Body, honeypotMaxRequest+1))
	if err != nil || len(kept) > honeypotMaxRequest {
		r.Body = honeypotBody{io.MultiReader(bytes.NewReader(kept), r.Body), r.Body}
		return nil, false
	}
	r.Body = honeypotBody{bytes.NewReader(kept), r.Body}
	return kept, true
}

// honeypotBody reads the kept body (and any rest) and closes the original.
type honeypotBody struct {
	io.Reader
	io.Closer
}

// honeypotWriter holds the origin's response until its status is known: a 404 is kept back (headers and
// up to honeypotMaxNotFound of body) for the honeypot to replace, anything else goes on as it came.
type honeypotWriter struct {
	under   http.ResponseWriter
	header  http.Header
	status  int
	caught  bool
	passing bool
	held    bytes.Buffer
}

func (w *honeypotWriter) Header() http.Header {
	if w.passing {
		return w.under.Header()
	}
	return w.header
}

func (w *honeypotWriter) WriteHeader(status int) {
	if w.status != 0 || status < 200 {
		// Informational answers (103 Early Hints) would show the origin's headers before it's decided.
		return
	}
	w.status = status
	if status == http.StatusNotFound {
		w.caught = true
		return
	}
	w.pass(status)
}

func (w *honeypotWriter) Write(p []byte) (int, error) {
	if w.status == 0 {
		w.WriteHeader(http.StatusOK)
	}
	if w.passing {
		return w.under.Write(p)
	}
	if w.held.Len()+len(p) <= honeypotMaxNotFound {
		return w.held.Write(p)
	}
	// Too big to hold: this 404 goes on as it is.
	w.caught = false
	w.pass(http.StatusNotFound)
	if _, err := w.under.Write(w.held.Bytes()); err != nil {
		return 0, err
	}
	w.held.Reset()
	return w.under.Write(p)
}

func (w *honeypotWriter) Flush() {
	if w.passing {
		_ = http.NewResponseController(w.under).Flush()
	}
}

func (w *honeypotWriter) Unwrap() http.ResponseWriter { return w.under }

// pass sends the held headers and status on; from now on everything goes straight through.
func (w *honeypotWriter) pass(status int) {
	w.keepHeaders()
	w.passing = true
	w.under.WriteHeader(status)
}

// keepHeaders makes the response's headers the held ones.
func (w *honeypotWriter) keepHeaders() {
	h := w.under.Header()
	for name := range h {
		if _, ok := w.header[name]; !ok {
			delete(h, name)
		}
	}
	for name, values := range w.header {
		h[name] = values
	}
}

// sendNotFound sends the origin's own 404 after all, as it came.
func (w *honeypotWriter) sendNotFound() error {
	w.caught = false
	w.pass(http.StatusNotFound)
	_, err := w.under.Write(w.held.Bytes())
	return err
}

// logHoneypot records what the honeypot did in the access log, once per request.
func logHoneypot(r *http.Request, trap *honeypotTrap, status int, took time.Duration, outcome string) {
	extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields)
	if !ok {
		return
	}
	extra.Add(zap.Int("honeypot", trap.honeypot))
	extra.Add(zap.Int("honeypot_trap", trap.id))
	extra.Add(zap.Int("honeypot_status", status))
	extra.Add(zap.Int64("honeypot_ms", took.Milliseconds()))
	extra.Add(zap.String("honeypot_outcome", outcome))
}

// honeypotTrap is one trap as the edge applies it: on its hosts, a path matching any of its patterns
// that the origin answers with a 404 goes to its honeypot.
type honeypotTrap struct {
	id, honeypot int
	origin       *url.URL
	secret       string
	timeout      time.Duration
	patterns     []*regexp.Regexp
}

// matches reports whether any of the request's judged paths fits one of the trap's patterns.
func (t *honeypotTrap) matches(paths []string) bool {
	for _, p := range paths {
		for _, pattern := range t.patterns {
			if pattern.MatchString(p) {
				return true
			}
		}
	}
	return false
}

// matchHoneypotTrap is the first of the host's traps the request's path fits, or nil.
func matchHoneypotTrap(traps []*honeypotTrap, paths []string) *honeypotTrap {
	for _, trap := range traps {
		if trap.matches(paths) {
			return trap
		}
	}
	return nil
}

var (
	honeypotClientOnce sync.Once
	honeypotClient     *http.Client
)

// sharedHoneypotClient is the one client every honeypot is asked with. A team chooses its honeypot, so
// like its origins it's only reached on a public address (unless NICETRY_ALLOW_PRIVATE_ORIGINS is set).
func sharedHoneypotClient() *http.Client {
	honeypotClientOnce.Do(func() {
		honeypotClient = newHoneypotClient(allowPrivateOrigins())
	})
	return honeypotClient
}

// newHoneypotClient never follows a honeypot's redirects (the visitor gets them) and, unless private is
// set, dials public addresses only.
func newHoneypotClient(private bool) *http.Client {
	rt := &http.Transport{
		Proxy:                 nil,
		DialContext:           (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSHandshakeTimeout:   10 * time.Second,
		MaxIdleConnsPerHost:   16,
		IdleConnTimeout:       90 * time.Second,
		ResponseHeaderTimeout: honeypotMaxTimeout,
		DisableCompression:    true,
	}
	if !private {
		dialPublicOnly(rt, sharedPublicLookup)
	}
	return &http.Client{
		Transport:     rt,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

type honeypotMap struct {
	current atomic.Pointer[map[string][]*honeypotTrap]

	endpoint, token string
	etag            string
}

var (
	honeypotMapsMu sync.Mutex
	honeypotMaps   = map[string]*honeypotMap{}
)

// sharedHoneypots returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedHoneypots(endpoint, token string, interval time.Duration) *honeypotMap {
	honeypotMapsMu.Lock()
	defer honeypotMapsMu.Unlock()

	key := endpoint + "\x00" + token
	if m, ok := honeypotMaps[key]; ok {
		return m
	}

	m := &honeypotMap{endpoint: endpoint, token: token}
	m.current.Store(&map[string][]*honeypotTrap{})
	honeypotMaps[key] = m
	go m.poll(interval)
	return m
}

func (m *honeypotMap) lookup(host string) []*honeypotTrap {
	return (*m.current.Load())[host]
}

// restore starts the traps from their last good copy.
func (m *honeypotMap) restore(store *lastGood) bool {
	traps, ok := restoreList(store, "honeypots", parseHoneypots)
	if ok {
		m.current.Store(&traps)
	}
	return ok
}

func (m *honeypotMap) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	m.restore(sharedLastGood())
	for {
		if err := m.refresh(client); err != nil {
			caddy.Log().Named("nicetry_honeypot").Warn("keeping the last honeypot traps", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks NiceTryBot for the traps. Anything but a good answer leaves the last list.
func (m *honeypotMap) refresh(client *http.Client) error {
	req, err := http.NewRequest(http.MethodGet, m.endpoint, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+m.token)
	req.Header.Set("Accept", "application/json")
	if m.etag != "" {
		req.Header.Set("If-None-Match", m.etag)
	}

	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotModified {
		return nil
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("honeypots returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, honeypotMaxPayload+1))
	if err != nil {
		return err
	}
	if len(raw) > honeypotMaxPayload {
		return fmt.Errorf("honeypots answer is over %d bytes", honeypotMaxPayload)
	}

	traps, err := parseHoneypots(raw)
	if err != nil {
		return err
	}
	m.current.Store(&traps)
	m.etag = resp.Header.Get("ETag")
	sharedLastGood().save("honeypots", raw)
	return nil
}

// parseHoneypots turns NiceTryBot's answer into each hostname's traps, in the order listed. The answer
// must name its traps (an empty list means none). Globs match the whole path (`*` is anything, slashes
// too) in any case; regexes match anywhere in it, as written. A trap with no honeypot it can reach (an
// http or https origin with a hostname), no secret or no pattern that compiles is skipped.
func parseHoneypots(raw []byte) (map[string][]*honeypotTrap, error) {
	var payload struct {
		Honeypots *[]struct {
			ID       int      `json:"id"`
			Honeypot int      `json:"honeypot"`
			Hosts    []string `json:"hosts"`
			Origin   string   `json:"origin"`
			Secret   string   `json:"secret"`
			Timeout  int      `json:"timeout"`
			Globs    []string `json:"globs"`
			Regexes  []string `json:"regexes"`
		} `json:"honeypots"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Honeypots == nil {
		return nil, fmt.Errorf("honeypots answer has no honeypots list")
	}

	traps := map[string][]*honeypotTrap{}
	for _, entry := range *payload.Honeypots {
		origin, err := url.Parse(entry.Origin)
		if err != nil || (origin.Scheme != "https" && origin.Scheme != "http") || origin.Hostname() == "" || entry.Secret == "" {
			continue
		}
		origin = &url.URL{Scheme: origin.Scheme, Host: origin.Host}

		trap := &honeypotTrap{id: entry.ID, honeypot: entry.Honeypot, origin: origin, secret: entry.Secret, timeout: honeypotDefaultTimeout}
		if entry.Timeout > 0 {
			trap.timeout = min(time.Duration(entry.Timeout)*time.Second, honeypotMaxTimeout)
		}
		for _, glob := range entry.Globs {
			re, err := compileGlob(glob, true)
			if err != nil {
				skippedPattern(glob, fmt.Sprintf("honeypot trap %d", entry.ID), err)
				continue
			}
			trap.patterns = append(trap.patterns, re)
		}
		for _, expr := range entry.Regexes {
			re, err := compilePattern(expr)
			if err != nil {
				skippedPattern(expr, fmt.Sprintf("honeypot trap %d", entry.ID), err)
				continue
			}
			trap.patterns = append(trap.patterns, re)
		}
		if len(trap.patterns) == 0 {
			continue
		}
		for _, host := range entry.Hosts {
			host = strings.ToLower(host)
			traps[host] = append(traps[host], trap)
		}
	}
	return traps, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_honeypot {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (hp *Honeypot) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			hp.Endpoint = raw
		case "token":
			hp.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			hp.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_honeypot option %q", key)
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*Honeypot)(nil)
	_ caddy.Validator             = (*Honeypot)(nil)
	_ caddyhttp.MiddlewareHandler = (*Honeypot)(nil)
	_ caddyfile.Unmarshaler       = (*Honeypot)(nil)
	_ http.Flusher                = (*honeypotWriter)(nil)
)
