package nicetrycapture

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
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
	caddy.RegisterModule(Lockdown{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_lockdown", parseLockdownCaddyfile)
}

const (
	// lockdownPassPath is where a pass link from the app lands. It is only special on a locked host, and
	// a bad link there gets the maintenance page like any other URL.
	lockdownPassPath = "/_q/pass"
	// lockdownPassCookie carries an admin's pass. The edge takes it off the request before the origin
	// hears of it.
	lockdownPassCookie = "_q_pass_k"
	// lockdownMaxPass is the longest a pass may run from the moment it is issued.
	lockdownMaxPass = 7 * 24 * time.Hour
	// lockdownMaxPayload caps how much of the app's answer is read.
	lockdownMaxPayload = 8 << 20
)

// lockdownFallbackHTML is served when a team didn't supply its own page: plain, benign, no branding.
const lockdownFallbackHTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scheduled Maintenance</title>
<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:#333;background:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}main{padding:24px;max-width:32rem}h1{font-size:1.5rem;font-weight:600;margin:0 0 .75rem}p{margin:0;line-height:1.5}</style>
</head>
<body>
<main>
<h1>Scheduled Maintenance</h1>
<p>We're performing scheduled maintenance and will be back shortly.</p>
</main>
</body>
</html>
`

// Lockdown is the nicetry_lockdown handler: the emergency switch a team flips in NiceTryBot (say, the
// night a critical PHP CVE drops). Every request to a locked hostname, whatever its method, gets a
// benign "Scheduled Maintenance" page as a 503 straight from memory: nothing reaches the origin, the
// gym, or anything else further in. Logged as action blocked, bot_tag lockdown.
//
// The team's admins still get through, either from an address on the lockdown's allow list (bot_tag
// lockdown_allow) or with a pass (bot_tag lockdown_pass). A pass starts as a signed link from the app to
// /_q/pass on the locked host, which the edge swaps for a signed cookie scoped to that host; the cookie
// is taken off every request before the origin sees it.
//
// The list is polled from the endpoint and, when a state file is set, kept on disk, so a Caddy restart
// while the app can't be reached leaves locked sites locked.
type Lockdown struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`
	// State is where the last good list is written, and read back when the poller starts.
	State string `json:"state,omitempty"`

	lockdowns *lockdownMap
}

// lockdown is one team's lockdown, shared by each of its hostnames.
type lockdown struct {
	team       int
	allow      []netip.Prefix
	secret     string // empty: no passes, only the allow list gets through
	html       []byte
	retryAfter string
}

// CaddyModule returns the Caddy module information.
func (Lockdown) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_lockdown",
		New: func() caddy.Module { return new(Lockdown) },
	}
}

// Provision starts (or joins) the poller for the endpoint.
func (l *Lockdown) Provision(caddy.Context) error {
	if l.Interval <= 0 {
		l.Interval = caddy.Duration(10 * time.Second)
	}
	l.lockdowns = sharedLockdowns(l.Endpoint, l.Token, l.State, time.Duration(l.Interval))
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (l *Lockdown) Validate() error {
	if l.Endpoint == "" || l.Token == "" {
		return fmt.Errorf("nicetry_lockdown needs an endpoint and a token")
	}
	return nil
}

func (l *Lockdown) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	host := strings.ToLower(hostOnly(r.Host))
	entry, ok := (*l.lockdowns.current.Load())[host]
	if !ok {
		return next.ServeHTTP(w, r)
	}
	now := time.Now()

	if (r.Method == http.MethodGet || r.Method == http.MethodHead) && r.URL.Path == lockdownPassPath {
		if passExp, ok := entry.checkLink(host, r.URL.Query().Get("t"), now); ok {
			return entry.issuePass(w, r, host, passExp)
		}
		return entry.serveMaintenance(w, r)
	}

	if entry.hasPass(r, host, now) {
		stripPassCookie(r)
		caddyhttp.SetVar(r.Context(), "bot_tag", "lockdown_pass")
		return next.ServeHTTP(w, r)
	}
	if entry.allows(lockdownClientIP(r)) {
		stripPassCookie(r)
		caddyhttp.SetVar(r.Context(), "bot_tag", "lockdown_allow")
		return next.ServeHTTP(w, r)
	}
	return entry.serveMaintenance(w, r)
}

// serveMaintenance answers with the lockdown's page. The request body is never read.
func (e *lockdown) serveMaintenance(w http.ResponseWriter, r *http.Request) error {
	logLockdown(r, "blocked", "lockdown")
	h := w.Header()
	h.Set("Content-Type", "text/html; charset=utf-8")
	h.Set("Cache-Control", "no-store")
	h.Set("Retry-After", e.retryAfter)
	h.Set("Content-Length", strconv.Itoa(len(e.html)))
	w.WriteHeader(http.StatusServiceUnavailable)
	if r.Method == http.MethodHead {
		return nil
	}
	_, err := w.Write(e.html)
	return err
}

// issuePass swaps a good pass link for the cookie and sends the admin to the site's front page.
func (e *lockdown) issuePass(w http.ResponseWriter, r *http.Request, host string, passExp int64) error {
	logLockdown(r, "passed", "lockdown_pass")
	exp := strconv.FormatInt(passExp, 10)
	http.SetCookie(w, &http.Cookie{
		Name:     lockdownPassCookie,
		Value:    exp + "." + lockdownMAC(e.secret, "pass", host, exp),
		Path:     "/",
		Expires:  time.Unix(passExp, 0),
		Secure:   true,
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
	})
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Location", "/")
	w.WriteHeader(http.StatusFound)
	return nil
}

// checkLink reports whether t (<linkExp>.<passExp>.<mac>) is a pass link the app signed for this host,
// still unused by the clock, for a pass that runs out within a week. It returns the pass's expiry.
func (e *lockdown) checkLink(host, t string, now time.Time) (int64, bool) {
	if e.secret == "" {
		return 0, false
	}
	parts := strings.Split(t, ".")
	if len(parts) != 3 {
		return 0, false
	}
	linkExp, err1 := strconv.ParseInt(parts[0], 10, 64)
	passExp, err2 := strconv.ParseInt(parts[1], 10, 64)
	if err1 != nil || err2 != nil || linkExp <= 0 || passExp <= 0 {
		return 0, false
	}
	want := lockdownMAC(e.secret, "link", host, strconv.FormatInt(linkExp, 10), strconv.FormatInt(passExp, 10))
	if !hmac.Equal([]byte(parts[2]), []byte(want)) {
		return 0, false
	}
	unix := now.Unix()
	if unix > linkExp || passExp <= unix || passExp-unix > int64(lockdownMaxPass/time.Second) {
		return 0, false
	}
	return passExp, true
}

// hasPass reports whether the request carries an unexpired pass cookie signed for this host.
func (e *lockdown) hasPass(r *http.Request, host string, now time.Time) bool {
	if e.secret == "" {
		return false
	}
	for _, c := range r.Cookies() {
		if c.Name != lockdownPassCookie {
			continue
		}
		exp, mac, ok := strings.Cut(c.Value, ".")
		if !ok {
			continue
		}
		passExp, err := strconv.ParseInt(exp, 10, 64)
		if err != nil || passExp <= now.Unix() {
			continue
		}
		if hmac.Equal([]byte(mac), []byte(lockdownMAC(e.secret, "pass", host, strconv.FormatInt(passExp, 10)))) {
			return true
		}
	}
	return false
}

// allows reports whether the visitor's address is on the lockdown's allow list.
func (e *lockdown) allows(ip string) bool {
	addr, err := netip.ParseAddr(ip)
	if err != nil {
		return false
	}
	addr = addr.Unmap()
	for _, prefix := range e.allow {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}

// lockdownMAC is the hex HMAC-SHA256 the app signs passes with: the team's secret as the key, the
// parts joined by newlines as the message.
func lockdownMAC(secret string, parts ...string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strings.Join(parts, "\n")))
	return hex.EncodeToString(mac.Sum(nil))
}

// stripPassCookie takes the pass cookie out of the request, leaving the site's own cookies as they were,
// so the origin never sees it. A Cookie header left with nothing in it is dropped.
func stripPassCookie(r *http.Request) {
	values := r.Header.Values("Cookie")
	if len(values) == 0 {
		return
	}
	kept := make([]string, 0, len(values))
	for _, value := range values {
		pairs := strings.Split(value, ";")
		out := pairs[:0]
		for _, pair := range pairs {
			name, _, _ := strings.Cut(pair, "=")
			if strings.TrimSpace(name) == lockdownPassCookie || strings.TrimSpace(pair) == "" {
				continue
			}
			out = append(out, strings.TrimSpace(pair))
		}
		if len(out) > 0 {
			kept = append(kept, strings.Join(out, "; "))
		}
	}
	r.Header.Del("Cookie")
	for _, value := range kept {
		r.Header.Add("Cookie", value)
	}
}

// lockdownClientIP is the visitor's address as nicetry_client worked it out (Cloudflare-aware), or the
// connection's own when that handler didn't run.
func lockdownClientIP(r *http.Request) string {
	if ip, ok := caddyhttp.GetVar(r.Context(), "nicetry_client_ip").(string); ok && ip != "" {
		return ip
	}
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		return host
	}
	return r.RemoteAddr
}

// logLockdown records what the lockdown did in the access log.
func logLockdown(r *http.Request, action, tag string) {
	caddyhttp.SetVar(r.Context(), "bot_tag", tag)
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("action", action))
	}
}

type lockdownMap struct {
	current atomic.Pointer[map[string]*lockdown]

	// Only the poller touches these (and the state load before it starts).
	endpoint, token, state string
	etag                   string
	raw                    []byte // the payload behind current, to spot an unchanged answer
}

var (
	lockdownMapsMu sync.Mutex
	lockdownMaps   = map[string]*lockdownMap{}
)

// sharedLockdowns returns one poller per endpoint, token and state file, shared by every site and kept
// across reloads. The state file is read before it returns, so the first requests after a restart see
// the last list the app gave.
func sharedLockdowns(endpoint, token, state string, interval time.Duration) *lockdownMap {
	lockdownMapsMu.Lock()
	defer lockdownMapsMu.Unlock()

	key := endpoint + "\x00" + token + "\x00" + state
	if m, ok := lockdownMaps[key]; ok {
		return m
	}

	m := &lockdownMap{endpoint: endpoint, token: token, state: state}
	m.current.Store(&map[string]*lockdown{})
	m.load()
	lockdownMaps[key] = m
	go m.poll(interval)
	return m
}

func (m *lockdownMap) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	for {
		if err := m.refresh(client); err != nil {
			caddy.Log().Named("nicetry_lockdown").Warn("keeping the last lockdowns", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks the app for the current lockdowns. Anything but a good answer leaves the last list in
// place; an unchanged one (304, or the same body) changes nothing. A new list is saved to the state file.
func (m *lockdownMap) refresh(client *http.Client) error {
	raw, etag, err := fetchLockdowns(client, m.endpoint, m.token, m.etag)
	if err != nil || raw == nil {
		return err
	}
	if bytes.Equal(raw, m.raw) {
		m.etag = etag
		return nil
	}
	locked, err := parseLockdowns(raw)
	if err != nil {
		return err
	}
	m.current.Store(&locked)
	m.etag, m.raw = etag, raw
	caddy.Log().Named("nicetry_lockdown").Info("lockdowns updated", zap.Int("hosts", len(locked)))
	m.save()
	return nil
}

// load reads the last list from the state file, if there is one the parser accepts.
func (m *lockdownMap) load() {
	if m.state == "" {
		return
	}
	logger := caddy.Log().Named("nicetry_lockdown")
	raw, err := os.ReadFile(m.state)
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			logger.Warn("couldn't read the lockdown state", zap.String("state", m.state), zap.Error(err))
		}
		return
	}
	locked, err := parseLockdowns(raw)
	if err != nil {
		logger.Warn("ignoring a lockdown state that doesn't parse", zap.String("state", m.state), zap.Error(err))
		return
	}
	m.current.Store(&locked)
	m.raw = raw
	logger.Info("lockdowns loaded from state", zap.String("state", m.state), zap.Int("hosts", len(locked)))
}

// save writes the payload behind the current list to the state file, atomically (a temp file in the
// same directory, renamed over it), readable by Caddy alone. A failure is logged, never fatal.
func (m *lockdownMap) save() {
	if m.state == "" {
		return
	}
	if err := writeFileAtomic(m.state, m.raw); err != nil {
		caddy.Log().Named("nicetry_lockdown").Warn("couldn't save the lockdown state", zap.String("state", m.state), zap.Error(err))
	}
}

func writeFileAtomic(path string, data []byte) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name) // a no-op once renamed

	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(name, path)
}

// fetchLockdowns asks for the current lockdowns and returns the raw answer. An unchanged list (304)
// comes back as nil, nil.
func fetchLockdowns(client *http.Client, endpoint, token, etag string) ([]byte, string, error) {
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
		return nil, "", fmt.Errorf("lockdowns returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, lockdownMaxPayload+1))
	if err != nil {
		return nil, "", err
	}
	if len(raw) > lockdownMaxPayload {
		return nil, "", fmt.Errorf("lockdowns answer is over %d bytes", lockdownMaxPayload)
	}
	return raw, resp.Header.Get("ETag"), nil
}

// parseLockdowns turns the app's answer into a map of locked hostnames. The answer must name its
// lockdowns (an empty list means nothing is locked), so an unrelated 200 can't unlock every site. Bad
// allow entries are skipped with a warning.
func parseLockdowns(raw []byte) (map[string]*lockdown, error) {
	var payload struct {
		Lockdowns *[]struct {
			Team       int      `json:"team"`
			Hosts      []string `json:"hosts"`
			Allow      []string `json:"allow"`
			Secret     string   `json:"secret"`
			HTML       string   `json:"html"`
			RetryAfter int      `json:"retry_after"`
		} `json:"lockdowns"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Lockdowns == nil {
		return nil, fmt.Errorf("lockdowns answer has no lockdowns list")
	}

	logger := caddy.Log().Named("nicetry_lockdown")
	locked := map[string]*lockdown{}
	for _, l := range *payload.Lockdowns {
		entry := &lockdown{
			team:       l.Team,
			secret:     l.Secret,
			html:       []byte(l.HTML),
			retryAfter: strconv.Itoa(l.RetryAfter),
		}
		if l.HTML == "" {
			entry.html = []byte(lockdownFallbackHTML)
		}
		if l.RetryAfter <= 0 {
			entry.retryAfter = "3600"
		}
		for _, raw := range l.Allow {
			prefix, err := parseAllowEntry(raw)
			if err != nil {
				logger.Warn("skipping a bad lockdown allow entry", zap.Int("team", l.Team), zap.String("allow", raw), zap.Error(err))
				continue
			}
			entry.allow = append(entry.allow, prefix)
		}
		for _, host := range l.Hosts {
			if host = strings.ToLower(strings.TrimSpace(host)); host != "" {
				locked[host] = entry
			}
		}
	}
	return locked, nil
}

// parseAllowEntry reads a bare address (a /32 or /128) or a CIDR range.
func parseAllowEntry(raw string) (netip.Prefix, error) {
	raw = strings.TrimSpace(raw)
	if strings.Contains(raw, "/") {
		prefix, err := netip.ParsePrefix(raw)
		if err != nil {
			return netip.Prefix{}, err
		}
		return prefix.Masked(), nil
	}
	addr, err := netip.ParseAddr(raw)
	if err != nil {
		return netip.Prefix{}, err
	}
	addr = addr.Unmap()
	return netip.PrefixFrom(addr, addr.BitLen()), nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_lockdown {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	    state    <path>
//	}
func (l *Lockdown) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			l.Endpoint = raw
		case "token":
			l.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			l.Interval = caddy.Duration(dur)
		case "state":
			l.State = raw
		default:
			return d.Errf("unknown nicetry_lockdown option %q", key)
		}
	}
	return nil
}

func parseLockdownCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	l := new(Lockdown)
	err := l.UnmarshalCaddyfile(h.Dispenser)
	return l, err
}

var (
	_ caddy.Provisioner           = (*Lockdown)(nil)
	_ caddy.Validator             = (*Lockdown)(nil)
	_ caddyhttp.MiddlewareHandler = (*Lockdown)(nil)
	_ caddyfile.Unmarshaler       = (*Lockdown)(nil)
)
