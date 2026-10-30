// Package nicetrycapture records redacted response bodies for the routes a NiceTryBot team chose to
// capture, and adds them to the access log so Vector ships them with the request.
//
// The edge asks NiceTryBot which routes to capture (GET <endpoint>, bearer token) every interval and
// keeps its last good answer if the app can't be reached. Requests on any other route pass through
// untouched; nothing about them is buffered.
package nicetrycapture

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	"unicode/utf8"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Capture{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_capture", parseCaddyfile)
}

// rawLimit is how much of a captured body is read before redaction. The logged excerpt is smaller.
const rawLimit = 256 * 1024

// Capture is the nicetry_capture handler.
type Capture struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	MaxBytes int            `json:"max_bytes,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	targets *targetSet
}

// CaddyModule returns the Caddy module information.
func (Capture) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_capture",
		New: func() caddy.Module { return new(Capture) },
	}
}

// Provision starts (or joins) the poller for the configured endpoint.
func (c *Capture) Provision(ctx caddy.Context) error {
	if c.MaxBytes <= 0 {
		c.MaxBytes = 4096
	}
	if c.Interval <= 0 {
		c.Interval = caddy.Duration(30 * time.Second)
	}
	c.targets = sharedTargets(c.Endpoint, c.Token, time.Duration(c.Interval), "captures", false)
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (c *Capture) Validate() error {
	if c.Endpoint == "" || c.Token == "" {
		return fmt.Errorf("nicetry_capture needs an endpoint and a token")
	}
	return nil
}

// ServeHTTP records the response of a request on a captured route; every other request passes through.
// A capture or tag limited to Livewire components reads the request body first to see which components
// it calls, and logs the one it matched as livewire_component.
func (c *Capture) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	candidates := c.targets.candidates(r)
	if len(candidates) == 0 {
		return next.ServeHTTP(w, r)
	}

	// Read what was sent before anything else can, so even a request the gym or a block answers is seen.
	var requestBody []byte
	read, inspected := false, false
	requestType, requestBytes := r.Header.Get("Content-Type"), r.ContentLength

	var components []string
	if slices.ContainsFunc(candidates, func(t *target) bool { return t.Component != nil }) && hasRequestBody(r) {
		requestBody, inspected = readRequestBody(r)
		read = true
		if inspected {
			components = livewireComponents(requestBody)
		}
	}

	target, component := pickTarget(candidates, components)
	if target == nil && component == "" {
		return next.ServeHTTP(w, r)
	}

	extra, _ := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields)
	if extra != nil && component != "" {
		extra.Add(zap.String("livewire_component", component))
	}
	if target == nil {
		return next.ServeHTTP(w, r)
	}

	sent := target.Request && hasRequestBody(r)
	if sent && !read {
		requestBody, inspected = readRequestBody(r)
	}

	// Ask the origin for an uncompressed body, so the excerpt is readable.
	r.Header.Del("Accept-Encoding")

	rec := &recorder{ResponseWriter: w}
	err := next.ServeHTTP(rec, r)

	if extra != nil {
		contentType := w.Header().Get("Content-Type")
		body := decode(rec.buf.Bytes(), w.Header().Get("Content-Encoding"))

		extra.Add(zap.Int("capture_id", target.ID))
		extra.Add(zap.String("response_type", contentType))
		extra.Add(zap.String("response_body", Redact(body, contentType, c.MaxBytes)))
		extra.Add(zap.Int64("response_bytes", rec.total))

		if sent {
			extra.Add(zap.String("request_type", requestType))
			if inspected {
				requestBytes = int64(len(requestBody))
				extra.Add(zap.String("request_body", RedactRequest(requestBody, requestType, c.MaxBytes)))
				if signals := Inspect(requestBody, requestType, target.Watch); !signals.empty() {
					extra.Add(zap.Any("signals", signals))
				}
			}
			if requestBytes >= 0 {
				extra.Add(zap.Int64("request_bytes", requestBytes))
			}
		}
	}

	return err
}

// pickTarget chooses the capture that records the request: the first one that isn't limited to Livewire
// components or calls one of them. component is the name a capture or tag matched, if any.
func pickTarget(candidates []*target, components []string) (chosen *target, component string) {
	for _, t := range candidates {
		name, ok := t.calls(components)
		if !ok {
			continue
		}
		if component == "" {
			component = name
		}
		if chosen == nil && !t.TagOnly {
			chosen = t
			if name != "" {
				component = name
			}
		}
	}
	return chosen, component
}

// calls reports whether a request calling these Livewire components is the target's, and which of
// them it matched. A target with no component takes every request.
func (t *target) calls(components []string) (string, bool) {
	if t.Component == nil {
		return "", true
	}
	for _, name := range components {
		if t.Component.MatchString(name) {
			return name, true
		}
	}
	return "", false
}

// livewireComponents names the components a Livewire v3 update calls: each component's snapshot is a
// JSON string whose memo holds its name.
func livewireComponents(body []byte) []string {
	var payload struct {
		Components []struct {
			Snapshot string `json:"snapshot"`
		} `json:"components"`
	}
	if json.Unmarshal(body, &payload) != nil {
		return nil
	}

	var names []string
	for _, c := range payload.Components {
		var snapshot struct {
			Memo struct {
				Name string `json:"name"`
			} `json:"memo"`
		}
		if json.Unmarshal([]byte(c.Snapshot), &snapshot) == nil && snapshot.Memo.Name != "" {
			names = append(names, snapshot.Memo.Name)
		}
	}
	return names
}

// recorder passes every byte through while keeping the first rawLimit of them.
type recorder struct {
	http.ResponseWriter
	buf   bytes.Buffer
	total int64
}

func (r *recorder) Write(p []byte) (int, error) {
	if room := rawLimit - r.buf.Len(); room > 0 {
		r.buf.Write(p[:min(room, len(p))])
	}
	n, err := r.ResponseWriter.Write(p)
	r.total += int64(n)
	return n, err
}

// Unwrap lets http.ResponseController reach the real writer to flush.
func (r *recorder) Unwrap() http.ResponseWriter { return r.ResponseWriter }

// decode gunzips a body the origin compressed anyway; a cut-off stream yields what could be read.
func decode(body []byte, encoding string) []byte {
	if !strings.EqualFold(strings.TrimSpace(encoding), "gzip") {
		return body
	}
	zr, err := gzip.NewReader(bytes.NewReader(body))
	if err != nil {
		return body
	}
	out, _ := io.ReadAll(io.LimitReader(zr, rawLimit))
	return out
}

// target is one capture, tag or gym trigger: a set of hostnames, an optional method, a path glob, an
// optional Livewire component glob and, for gym triggers limited to rule verdicts, the clients (IP
// and/or JA4) it applies to.
type target struct {
	ID      int
	Hosts   map[string]bool
	Method  string
	Path    *regexp.Regexp
	Clients []clientFilter
	// Limited is set on gym triggers for rule verdicts (from an app that sends rules): only their clients
	// count, or requests matching one of their Rules, even when no client is listed.
	Limited bool
	// Rules are the single-request rules whose verdicts lead to the trigger's routine (see rules.go).
	Rules []edgeRule
	env   *ruleEnv
	// Request is set on captures that also record what was sent, checked against Watch.
	Request bool
	Watch   []watchword
	// Component limits the target to Livewire v3 updates that call a component it matches.
	Component *regexp.Regexp
	// TagOnly is set on tags: they log the Livewire component a request calls and record nothing.
	TagOnly bool
}

// clientFilter is one tagged visitor; an empty field matches anything.
type clientFilter struct {
	IP  string
	JA4 string
}

type targetSet struct {
	current atomic.Pointer[[]target]
}

var (
	pollersMu sync.Mutex
	pollers   = map[string]*targetSet{}
)

// sharedTargets returns one poller per endpoint and token, shared by every site and kept across reloads.
// listKey names the JSON array to read; foldCase makes path globs match regardless of case.
func sharedTargets(endpoint, token string, interval time.Duration, listKey string, foldCase bool) *targetSet {
	pollersMu.Lock()
	defer pollersMu.Unlock()

	key := endpoint + "\x00" + token
	if ts, ok := pollers[key]; ok {
		return ts
	}

	ts := &targetSet{}
	ts.current.Store(&[]target{})
	pollers[key] = ts
	go ts.poll(endpoint, token, interval, listKey, foldCase)
	return ts
}

func (ts *targetSet) poll(endpoint, token string, interval time.Duration, listKey string, foldCase bool) {
	client := &http.Client{Timeout: 10 * time.Second}
	logger := caddy.Log().Named("nicetry_" + listKey)
	lastCount := -1
	etag := ""

	for {
		targets, newEtag, err := fetchTargets(client, endpoint, token, listKey, foldCase, etag)
		switch {
		case err != nil:
			logger.Warn("keeping the last list", zap.String("list", listKey), zap.Error(err))
		case targets != nil:
			ts.current.Store(&targets)
			etag = newEtag
			if len(targets) != lastCount {
				logger.Info("list updated", zap.String("list", listKey), zap.Int(listKey, len(targets)))
				lastCount = len(targets)
			}
		}
		time.Sleep(interval)
	}
}

// fetchTargets asks for the current list. An unchanged list (304) comes back as nil, nil; an empty one
// as an empty, non-nil slice.
func fetchTargets(client *http.Client, endpoint, token, listKey string, foldCase bool, etag string) ([]target, string, error) {
	req, err := http.NewRequest(http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, "", err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	// This edge judges single-request rules (rules.go): send them, with what they need.
	req.Header.Set("X-NiceTry-Features", gymRulesFeature)
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
		return nil, "", fmt.Errorf("%s list returned %d", listKey, resp.StatusCode)
	}

	type entry struct {
		ID      int      `json:"id"`
		Hosts   []string `json:"hosts"`
		Method  *string  `json:"method"`
		Path    string   `json:"path"`
		Clients []struct {
			IP  *string `json:"ip"`
			JA4 *string `json:"ja4"`
		} `json:"clients"`
		Limited bool `json:"limited"`
		Rules   []struct {
			ID   int       `json:"id"`
			Expr *ruleExpr `json:"expr"`
		} `json:"rules"`
		Request   bool    `json:"request"`
		Component *string `json:"component"`
		Watch     []struct {
			ID     int    `json:"id"`
			Kind   string `json:"kind"`
			Value  string `json:"value"`
			SHA256 string `json:"sha256"`
		} `json:"watch"`
	}

	// A trigger list for rule verdicts lists every client holding one, so it can run large.
	var payload map[string]json.RawMessage
	if err := json.NewDecoder(io.LimitReader(resp.Body, 16<<20)).Decode(&payload); err != nil {
		return nil, "", err
	}

	var entries []entry
	if raw, ok := payload[listKey]; ok {
		if err := json.Unmarshal(raw, &entries); err != nil {
			return nil, "", err
		}
	}

	// Tags (threat monitors on a Livewire component) ride along with the captures: older edges ignore them.
	tagsFrom := len(entries)
	if listKey == "captures" {
		var tagEntries []entry
		if raw, ok := payload["tags"]; ok && json.Unmarshal(raw, &tagEntries) == nil {
			entries = append(entries, tagEntries...)
		}
	}

	env := ruleEnvFrom(payload)

	targets := make([]target, 0, len(entries))
	for i, c := range entries {
		hosts := map[string]bool{}
		for _, h := range c.Hosts {
			hosts[strings.ToLower(h)] = true
		}
		method := ""
		if c.Method != nil {
			method = strings.ToUpper(*c.Method)
		}
		// A pattern too large to compile is left out, not panicked over: the poller runs outside any
		// request, so a panic would take Caddy down on every edge, again on every restart.
		glob, err := compileGlob(c.Path, foldCase)
		if err != nil {
			skippedPattern(listKey+" path", strconv.Itoa(c.ID), err)
			continue
		}
		var clients []clientFilter
		for _, cl := range c.Clients {
			var ip, ja4 string
			if cl.IP != nil {
				ip = *cl.IP
			}
			if cl.JA4 != nil {
				ja4 = *cl.JA4
			}
			clients = append(clients, clientFilter{IP: ip, JA4: ja4})
		}
		var watch []watchword
		for _, w := range c.Watch {
			watch = append(watch, watchword{ID: w.ID, Kind: w.Kind, Value: strings.ToLower(w.Value), SHA256: strings.ToLower(w.SHA256)})
		}
		var component *regexp.Regexp
		if c.Component != nil && *c.Component != "" {
			if component, err = compileGlob(*c.Component, false); err != nil {
				skippedPattern(listKey+" component", strconv.Itoa(c.ID), err)
				continue
			}
		}
		tagOnly := i >= tagsFrom
		if tagOnly {
			watch = nil
		}
		var rules []edgeRule
		for _, rule := range c.Rules {
			if rule.Expr != nil {
				rule.Expr.compile()
				rules = append(rules, edgeRule{ID: rule.ID, Expr: rule.Expr})
			}
		}
		targets = append(targets, target{ID: c.ID, Hosts: hosts, Method: method, Path: glob, Clients: clients, Limited: c.Limited, Rules: rules, env: env, Request: c.Request && !tagOnly, Watch: watch, Component: component, TagOnly: tagOnly})
	}
	return targets, resp.Header.Get("ETag"), nil
}

// GlobRegexp turns a capture path into an anchored regexp: `*` matches anything, including slashes.
// It panics on a glob too large to compile; data from the app goes through compileGlob.
func GlobRegexp(glob string) *regexp.Regexp {
	re, err := compileGlob(glob, false)
	if err != nil {
		panic(err)
	}
	return re
}

// compileGlob is GlobRegexp with an error for a glob too large to compile, optionally ignoring case.
func compileGlob(glob string, foldCase bool) (*regexp.Regexp, error) {
	flags := ""
	if foldCase {
		flags = "(?i)"
	}
	return compilePattern(flags + "^" + strings.ReplaceAll(regexp.QuoteMeta(glob), `\*`, ".*") + "$")
}

// skippedPattern logs a pattern from the app that couldn't be compiled. Go's error quotes the whole
// pattern, which can run to megabytes, so only its start is logged.
// maxPatternBytes is the longest regular expression the edge compiles from what the app sends. Real
// triggers, rules and categories are a few hundred bytes; a huge one would cost every edge seconds of
// CPU on each poll before Go gave up on it anyway.
const maxPatternBytes = 64 << 10

// compilePattern compiles a regular expression built from the app's data, refusing one too long to be
// real without trying.
func compilePattern(expr string) (*regexp.Regexp, error) {
	if len(expr) > maxPatternBytes {
		return nil, fmt.Errorf("pattern is %d bytes, more than %d", len(expr), maxPatternBytes)
	}
	return regexp.Compile(expr)
}

func skippedPattern(what, which string, err error) {
	msg := err.Error()
	if len(msg) > 200 {
		msg = msg[:200] + "…"
	}
	caddy.Log().Named("nicetry_patterns").Warn("skipping a pattern that doesn't compile",
		zap.String("pattern", what), zap.String("which", truncate(which, 100)), zap.String("error", msg))
}

func (ts *targetSet) match(r *http.Request) *target {
	t, _ := ts.matchOn(r)
	return t
}

// matchOn is the first target that fits the request, and the first of the request's judged paths its
// path glob matches.
func (ts *targetSet) matchOn(r *http.Request) (*target, string) {
	candidates := ts.candidates(r)
	if len(candidates) == 0 {
		return nil, ""
	}
	for _, p := range judgedPaths(r) {
		if candidates[0].Path.MatchString(p) {
			return candidates[0], p
		}
	}
	return candidates[0], r.URL.Path
}

// candidates lists the targets whose host, method, path and clients fit the request, in list order.
func (ts *targetSet) candidates(r *http.Request) []*target {
	targets := *ts.current.Load()
	if len(targets) == 0 {
		return nil
	}

	host := strings.ToLower(r.Host)
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}

	var found []*target
	for i := range targets {
		t := &targets[i]
		if t.Hosts[host] && (t.Method == "" || t.Method == r.Method) && t.matchesPath(r) && t.admits(r) {
			found = append(found, t)
		}
	}
	return found
}

// matchesPath reports whether the target's path glob matches any of the request's judged paths.
func (t *target) matchesPath(r *http.Request) bool {
	for _, p := range judgedPaths(r) {
		if t.Path.MatchString(p) {
			return true
		}
	}
	return false
}

// admits reports whether the request comes from one of the target's clients (any client, if it has
// none and isn't limited). A request carrying a gym cookie is always admitted: browsers present a
// different JA4 when they resume a TLS session or switch to HTTP/3, so NiceTryBot recognises them by
// that cookie instead. So is one matching one of the target's single-request rules, which the request
// remembers so the gym check can reach the rule's verdict on it.
func (t *target) admits(r *http.Request) bool {
	if len(t.Clients) == 0 && !t.Limited {
		return true
	}
	if ids := t.matchingRules(r); len(ids) > 0 {
		rememberRules(r, ids)
		return true
	}
	for name := range gymCookies {
		if _, err := r.Cookie(name); err == nil {
			return true
		}
	}

	ip, ja4 := requestIP(r), ""
	if repl, ok := r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer); ok {
		ja4 = repl.ReplaceAll("{tls.ja4}", "")
	}

	for _, c := range t.Clients {
		if (c.IP == "" || c.IP == ip) && (c.JA4 == "" || c.JA4 == ja4) {
			return true
		}
	}
	return false
}

// requestIP is the visitor's address as Caddy resolved it, or the connection's when that isn't set.
func requestIP(r *http.Request) string {
	if ip, ok := caddyhttp.GetVar(r.Context(), caddyhttp.ClientIPVarKey).(string); ok && ip != "" {
		return ip
	}
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		return host
	}
	return r.RemoteAddr
}

var (
	// Keys whose values never leave the node.
	sensitiveKey = regexp.MustCompile(`(?i)(pass(word|wd|phrase)?|pwd|secret|token|csrf|xsrf|api[_-]?key|authori[sz]ation|cookie|session|checksum|signature|otp|cvv|ssn)`)
	// key=value / "key": "value" pairs in non-JSON text.
	sensitivePair = regexp.MustCompile(`(?i)((?:pass(?:word|wd)?|pwd|secret|_?token|csrf[_-]?token|api[_-]?key|session)["']?\s*[:=]\s*)("[^"]*"|'[^']*'|[^\s,&}<"']+)`)
	// <meta name="csrf-token" content="…">
	csrfMeta = regexp.MustCompile(`(?i)(name=["']csrf-token["']\s+content=["'])[^"']*`)
	email    = regexp.MustCompile(`([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})`)
	scripts  = regexp.MustCompile(`(?is)<(script|style|svg|noscript)\b.*?</(script|style|svg|noscript)>`)
	tags     = regexp.MustCompile(`(?s)<[^>]*>`)
	spaces   = regexp.MustCompile(`\s+`)
)

// longString is the most of any one JSON string kept, so nested errors survive a large rendered
// HTML field (Livewire v2 sends the whole component's HTML in effects.html).
const longString = 300

// Redact masks secrets and personal data in a body and shortens it to at most maxBytes.
func Redact(body []byte, contentType string, maxBytes int) string {
	text := strings.ToValidUTF8(string(body), "")
	ct := strings.ToLower(contentType)

	switch {
	case strings.Contains(ct, "json") || json.Valid(body):
		var decoded any
		dec := json.NewDecoder(strings.NewReader(text))
		dec.UseNumber()
		if dec.Decode(&decoded) == nil {
			var out bytes.Buffer
			enc := json.NewEncoder(&out)
			enc.SetEscapeHTML(false)
			if enc.Encode(redactValue(decoded)) == nil {
				return truncate(strings.TrimSpace(out.String()), maxBytes)
			}
		}
		text = redactText(text)
	case strings.Contains(ct, "html"):
		text = csrfMeta.ReplaceAllString(text, "${1}[redacted]")
		text = redactText(htmlText(text))
	default:
		text = redactText(text)
	}

	return truncate(text, maxBytes)
}

func redactValue(v any) any {
	switch value := v.(type) {
	case map[string]any:
		// A Livewire v2 update names its field: {"name": "password", "value": "…"}.
		name, _ := value["name"].(string)
		for k, child := range value {
			if child != nil && (sensitiveKey.MatchString(k) || (k == "value" && sensitiveKey.MatchString(name))) {
				value[k] = "[redacted]"
			} else {
				value[k] = redactValue(child)
			}
		}
		return value
	case []any:
		for i, child := range value {
			value[i] = redactValue(child)
		}
		return value
	case string:
		// A JSON object inside a string (a Livewire v3 snapshot) is redacted like the rest.
		if nested, ok := jsonInString(value); ok {
			var out bytes.Buffer
			enc := json.NewEncoder(&out)
			enc.SetEscapeHTML(false)
			if enc.Encode(redactValue(nested)) != nil {
				return "[redacted]"
			}
			value = strings.TrimSpace(out.String())
		}
		s := email.ReplaceAllString(value, "${1}***@${2}")
		if n := utf8.RuneCountInString(s); n > longString {
			s = string([]rune(s)[:longString]) + "…[" + strconv.Itoa(n) + " chars]"
		}
		return s
	default:
		return value
	}
}

func redactText(s string) string {
	s = sensitivePair.ReplaceAllString(s, `${1}"[redacted]"`)
	return email.ReplaceAllString(s, "${1}***@${2}")
}

// htmlText keeps a page's visible text, where error messages live, and drops its markup.
func htmlText(s string) string {
	s = scripts.ReplaceAllString(s, " ")
	s = tags.ReplaceAllString(s, " ")
	return strings.TrimSpace(spaces.ReplaceAllString(s, " "))
}

func truncate(s string, maxBytes int) string {
	if len(s) <= maxBytes {
		return s
	}
	cut := maxBytes
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut] + "…"
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_capture {
//	    endpoint  <url>
//	    token     <token>
//	    max_bytes <n>
//	    interval  <duration>
//	}
func (c *Capture) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "endpoint":
			if !d.Args(&c.Endpoint) {
				return d.ArgErr()
			}
		case "token":
			if !d.Args(&c.Token) {
				return d.ArgErr()
			}
		case "max_bytes":
			var raw string
			if !d.Args(&raw) {
				return d.ArgErr()
			}
			n, err := strconv.Atoi(raw)
			if err != nil {
				return d.Errf("max_bytes: %v", err)
			}
			c.MaxBytes = n
		case "interval":
			var raw string
			if !d.Args(&raw) {
				return d.ArgErr()
			}
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			c.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_capture option %q", d.Val())
		}
	}
	return nil
}

func parseCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	c := new(Capture)
	err := c.UnmarshalCaddyfile(h.Dispenser)
	return c, err
}

var (
	_ caddy.Provisioner           = (*Capture)(nil)
	_ caddy.Validator             = (*Capture)(nil)
	_ caddyhttp.MiddlewareHandler = (*Capture)(nil)
	_ caddyfile.Unmarshaler       = (*Capture)(nil)
)
