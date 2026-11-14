package nicetrycapture

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"mime"
	"net/http"
	"regexp"
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
	caddy.RegisterModule(Rewrite{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_rewrite", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		rw := new(Rewrite)
		return rw, rw.UnmarshalCaddyfile(h.Dispenser)
	})
}

const (
	rewriteMaxPayload = 16 << 20
	rewriteMaxBody    = 4 << 20
	// A rewritten header value can't grow past this (or its own length, if that's longer).
	rewriteMaxHeader = 16 << 10
)

// Rewrite is the nicetry_rewrite handler. It polls NiceTryBot for the teams' response rewrites (text to
// find and what it becomes, on some hostnames) and changes the bodies of origins' text responses on
// those hostnames before they go on. It belongs right around reverse_proxy, inside encode, so it reads
// what the origin sent and encode compresses what it wrote. If NiceTryBot can't be reached the last
// list stays.
//
// On a hostname with rewrites the origin is asked for an uncompressed, whole answer (Accept-Encoding:
// identity, no Range), so there's something to read. A body it can't read passes untouched: one that
// isn't text (or HTML, for an HTML-only rewrite), one the origin compressed anyway, and one over
// max_body. So do HEAD requests' and upgrades' (WebSockets). A rewrite that covers headers too changes
// every response's header values (Set-Cookie's Domain, Location, CORS origins), whatever its body,
// except the ones that frame it (lengths, encodings, type).
//
// A rewrite can't blow a response up: a body whose rewrite would come out over twice max_body goes on as
// it came, and so does a header value whose rewrite would come out over 16 KiB.
//
// An asset rewrite (the S3 Assets add-on, an entry with `assets`) moves the URLs of a site's static files
// to its CDN host, in HTML pages only: absolute ones on the site's hostnames (also protocol-relative,
// and JSON-escaped in inline scripts) and root-relative ones in attributes, srcset and url(). Never in
// a stylesheet or script, so a file the CDN pulls is byte for byte the origin's and its Subresource
// Integrity hash still holds. Pages under its skip paths are left as they are.
type Rewrite struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`
	MaxBody  int            `json:"max_body,omitempty"`

	rewrites *rewriteMap
}

// CaddyModule returns the Caddy module information.
func (Rewrite) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_rewrite",
		New: func() caddy.Module { return new(Rewrite) },
	}
}

// Provision starts (or joins) the rewrite poller.
func (rw *Rewrite) Provision(caddy.Context) error {
	if rw.Interval <= 0 {
		rw.Interval = caddy.Duration(10 * time.Second)
	}
	if rw.MaxBody <= 0 {
		rw.MaxBody = rewriteMaxBody
	}
	rw.rewrites = sharedRewrites(rw.Endpoint, rw.Token, time.Duration(rw.Interval))
	return nil
}

// Validate checks the handler has an endpoint and a token.
func (rw *Rewrite) Validate() error {
	if rw.Endpoint == "" || rw.Token == "" {
		return fmt.Errorf("nicetry_rewrite needs endpoint and token")
	}
	return nil
}

func (rw *Rewrite) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	rules := rulesForPath(rw.rewrites.lookup(strings.ToLower(hostOnly(r.Host))), r.URL.Path)
	if len(rules) == 0 || r.Header.Get("Upgrade") != "" {
		return next.ServeHTTP(w, r)
	}
	readBody := r.Method != http.MethodHead
	if !readBody && !anyHeaderRule(rules) {
		return next.ServeHTTP(w, r)
	}

	upstream := r
	if readBody {
		// A copy for the origin, so the access log keeps what the visitor sent.
		upstream = r.Clone(r.Context())
		upstream.Header.Set("Accept-Encoding", "identity")
		upstream.Header.Del("Range")
		upstream.Header.Del("If-Range")
	}

	rec := &rewriteWriter{ResponseWriter: w, rules: rules, max: rw.MaxBody, readBody: readBody}
	err := next.ServeHTTP(rec, upstream)
	if err != nil && rec.buffering {
		// Nothing has gone to the visitor yet, so the error handler can still answer.
		return err
	}
	if finishErr := rec.finish(); err == nil {
		err = finishErr
	}
	return err
}

// rewriteRule is one rewrite as the edge applies it.
type rewriteRule struct {
	find     []byte
	replace  []byte
	pattern  *regexp.Regexp // set when the rewrite ignores case
	htmlOnly bool
	assets   *assetRewrite // set for an asset rewrite, which find and replace don't apply to

	headers       bool   // header values too
	headerReplace []byte // replace with line breaks as spaces, so it can't start a header of its own
}

// apply rewrites body, or says (false) it would come out over limit.
func (rule rewriteRule) apply(body []byte, limit int) ([]byte, bool) {
	if rule.assets != nil {
		return rule.assets.apply(body), true
	}
	return rule.replaceIn(body, rule.replace, limit)
}

// applyHeader rewrites a header value, leaving it as it was if the rewrite would come out too long.
func (rule rewriteRule) applyHeader(value string) string {
	out, ok := rule.replaceIn([]byte(value), rule.headerReplace, max(rewriteMaxHeader, len(value)))
	if !ok {
		return value
	}
	return string(out)
}

// replaceIn replaces every match in text with replace, giving up (false) as soon as the result would be
// longer than limit, so a short find with a long replacement can't make one response take gigabytes.
func (rule rewriteRule) replaceIn(text, replace []byte, limit int) ([]byte, bool) {
	var out []byte
	done := 0
	for done < len(text) {
		start, end := rule.match(text, done)
		if start < 0 || end <= start {
			break
		}
		if len(out)+(start-done)+len(replace) > limit {
			return text, false
		}
		if out == nil {
			out = make([]byte, 0, min(len(text), limit))
		}
		out = append(out, text[done:start]...)
		out = append(out, replace...)
		done = end
	}
	if out == nil {
		return text, len(text) <= limit
	}
	if len(out)+len(text)-done > limit {
		return text, false
	}
	return append(out, text[done:]...), true
}

// match finds the rule's next match in text at or after from, or -1.
func (rule rewriteRule) match(text []byte, from int) (start, end int) {
	if rule.pattern != nil {
		loc := rule.pattern.FindIndex(text[from:])
		if loc == nil {
			return -1, -1
		}
		return from + loc[0], from + loc[1]
	}
	i := bytes.Index(text[from:], rule.find)
	if i < 0 {
		return -1, -1
	}
	return from + i, from + i + len(rule.find)
}

func anyHeaderRule(rules []rewriteRule) bool {
	for _, rule := range rules {
		if rule.headers {
			return true
		}
	}
	return false
}

// rewriteFramingHeaders say how the body is sent; changing them would break the response.
var rewriteFramingHeaders = map[string]bool{
	"Content-Length":    true,
	"Content-Encoding":  true,
	"Content-Type":      true,
	"Transfer-Encoding": true,
	"Connection":        true,
	"Trailer":           true,
}

// rewriteHeaders changes the header values the header rules cover, in place.
func rewriteHeaders(h http.Header, rules []rewriteRule) {
	for _, rule := range rules {
		if !rule.headers {
			continue
		}
		for name, values := range h {
			if rewriteFramingHeaders[name] {
				continue
			}
			for i, value := range values {
				values[i] = rule.applyHeader(value)
			}
		}
	}
}

// rewriteWriter holds back a readable response until it's complete, then writes it rewritten. One that
// turns out too big goes on as it came, from the point it got too big.
type rewriteWriter struct {
	http.ResponseWriter

	rules    []rewriteRule
	max      int
	readBody bool // false for HEAD: headers only

	status    int
	decided   bool
	buffering bool
	applying  []rewriteRule
	buf       bytes.Buffer
}

// Unwrap lets http.ResponseController reach the writer underneath (for deadlines and the like).
func (w *rewriteWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

func (w *rewriteWriter) WriteHeader(status int) {
	if w.decided {
		return
	}
	rewriteHeaders(w.Header(), w.rules)
	if status < 200 {
		// Informational (103 Early Hints): passes on, the real answer is still to come.
		w.ResponseWriter.WriteHeader(status)
		return
	}
	w.decided = true
	w.status = status
	w.applying = w.readable(status, w.Header())
	if len(w.applying) == 0 {
		w.ResponseWriter.WriteHeader(status)
		return
	}
	w.buffering = true
}

// readable returns the rules that apply to a response with this status and these headers: none when
// it has no body to read, is compressed, or says it's bigger than the cap.
func (w *rewriteWriter) readable(status int, h http.Header) []rewriteRule {
	if !w.readBody || status == http.StatusNoContent || status == http.StatusNotModified {
		return nil
	}
	if enc := h.Get("Content-Encoding"); enc != "" && !strings.EqualFold(enc, "identity") {
		return nil
	}
	if cl := h.Get("Content-Length"); cl != "" {
		if n, err := strconv.ParseInt(cl, 10, 64); err != nil || n > int64(w.max) {
			return nil
		}
	}
	html, text := rewriteContentKind(h.Get("Content-Type"))
	if !text {
		return nil
	}
	var rules []rewriteRule
	for _, rule := range w.rules {
		if html || !rule.htmlOnly {
			rules = append(rules, rule)
		}
	}
	return rules
}

func (w *rewriteWriter) Write(p []byte) (int, error) {
	if !w.decided {
		w.WriteHeader(http.StatusOK)
	}
	if !w.buffering {
		return w.ResponseWriter.Write(p)
	}
	if w.buf.Len()+len(p) > w.max {
		if err := w.passOn(); err != nil {
			return 0, err
		}
		return w.ResponseWriter.Write(p)
	}
	return w.buf.Write(p)
}

// Flush waits while the response is held back (or not yet begun): it goes out whole, rewritten, once
// complete.
func (w *rewriteWriter) Flush() {
	if w.buffering || !w.decided {
		return
	}
	_ = http.NewResponseController(w.ResponseWriter).Flush()
}

// passOn gives up on rewriting: what's held back goes out as it came, and the rest follows as it comes.
func (w *rewriteWriter) passOn() error {
	w.buffering = false
	w.ResponseWriter.WriteHeader(w.status)
	_, err := w.ResponseWriter.Write(w.buf.Bytes())
	w.buf = bytes.Buffer{}
	return err
}

// finish writes a held-back response, rewritten, with its new length.
func (w *rewriteWriter) finish() error {
	if !w.buffering {
		return nil
	}
	w.buffering = false

	body := w.buf.Bytes()
	for _, rule := range w.applying {
		rewritten, ok := rule.apply(body, 2*w.max)
		if !ok {
			// Rewritten it would be too big to hold, so it goes on as the origin sent it.
			body = w.buf.Bytes()
			break
		}
		body = rewritten
	}

	h := w.Header()
	h.Set("Content-Length", strconv.Itoa(len(body)))
	h.Del("Content-MD5")
	h.Del("Accept-Ranges")
	w.ResponseWriter.WriteHeader(w.status)
	_, err := w.ResponseWriter.Write(body)
	return err
}

// rewriteContentKind says whether a Content-Type is an HTML page, and whether it's text the edge may
// read at all (HTML, CSS, JavaScript, JSON, XML, plain text; never an event stream, which never ends).
func rewriteContentKind(contentType string) (html, text bool) {
	mediaType, _, err := mime.ParseMediaType(contentType)
	if err != nil {
		return false, false
	}
	switch {
	case mediaType == "text/html", mediaType == "application/xhtml+xml":
		return true, true
	case mediaType == "text/event-stream":
		return false, false
	case strings.HasPrefix(mediaType, "text/"),
		mediaType == "application/javascript", mediaType == "application/x-javascript",
		mediaType == "application/json", strings.HasSuffix(mediaType, "+json"),
		mediaType == "application/xml", strings.HasSuffix(mediaType, "+xml"):
		return false, true
	}
	return false, false
}

// rulesForPath leaves out the asset rewrites whose skip paths cover the page's path.
func rulesForPath(rules []rewriteRule, p string) []rewriteRule {
	for i, rule := range rules {
		if rule.assets == nil || !rule.assets.skipped(p) {
			continue
		}
		kept := append([]rewriteRule{}, rules[:i]...)
		for _, rest := range rules[i+1:] {
			if rest.assets == nil || !rest.assets.skipped(p) {
				kept = append(kept, rest)
			}
		}
		return kept
	}
	return rules
}

type rewriteMap struct {
	current atomic.Pointer[map[string][]rewriteRule]

	endpoint, token string
	etag            string
}

var (
	rewriteMapsMu sync.Mutex
	rewriteMaps   = map[string]*rewriteMap{}
)

// sharedRewrites returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedRewrites(endpoint, token string, interval time.Duration) *rewriteMap {
	rewriteMapsMu.Lock()
	defer rewriteMapsMu.Unlock()

	key := endpoint + "\x00" + token
	if m, ok := rewriteMaps[key]; ok {
		return m
	}

	m := &rewriteMap{endpoint: endpoint, token: token}
	m.current.Store(&map[string][]rewriteRule{})
	rewriteMaps[key] = m
	go m.poll(interval)
	return m
}

func (m *rewriteMap) lookup(host string) []rewriteRule {
	return (*m.current.Load())[host]
}

// restore starts the rewrites from their last good copy.
func (m *rewriteMap) restore(store *lastGood) bool {
	rewrites, ok := restoreList(store, "rewrites", parseRewrites)
	if ok {
		m.current.Store(&rewrites)
	}
	return ok
}

func (m *rewriteMap) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	m.restore(sharedLastGood())
	for {
		if err := m.refresh(client); err != nil {
			caddy.Log().Named("nicetry_rewrite").Warn("keeping the last rewrites", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks NiceTryBot for the rewrites. Anything but a good answer leaves the last list.
func (m *rewriteMap) refresh(client *http.Client) error {
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
		return fmt.Errorf("rewrites returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, rewriteMaxPayload+1))
	if err != nil {
		return err
	}
	if len(raw) > rewriteMaxPayload {
		return fmt.Errorf("rewrites answer is over %d bytes", rewriteMaxPayload)
	}

	rewrites, err := parseRewrites(raw)
	if err != nil {
		return err
	}
	m.current.Store(&rewrites)
	m.etag = resp.Header.Get("ETag")
	sharedLastGood().save("rewrites", raw)
	return nil
}

// parseRewrites turns NiceTryBot's answer into each hostname's rules, in the order listed. The answer
// must name its rewrites (an empty list means none). A rewrite with nothing to find is skipped.
func parseRewrites(raw []byte) (map[string][]rewriteRule, error) {
	var payload struct {
		Rewrites *[]struct {
			Hosts      []string `json:"hosts"`
			Find       string   `json:"find"`
			Replace    string   `json:"replace"`
			IgnoreCase bool     `json:"ignore_case"`
			In         string   `json:"in"`
			Headers    bool     `json:"headers"`
			Assets     *struct {
				To         string   `json:"to"`
				Extensions []string `json:"extensions"`
				SkipPaths  []string `json:"skip_paths"`
			} `json:"assets"`
		} `json:"rewrites"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Rewrites == nil {
		return nil, fmt.Errorf("rewrites answer has no rewrites list")
	}

	rewrites := map[string][]rewriteRule{}
	for _, entry := range *payload.Rewrites {
		if entry.Assets != nil {
			assets := newAssetRewrite(entry.Hosts, entry.Assets.To, entry.Assets.Extensions, entry.Assets.SkipPaths)
			if assets == nil {
				continue
			}
			for _, host := range entry.Hosts {
				host = strings.ToLower(host)
				rewrites[host] = append(rewrites[host], rewriteRule{htmlOnly: true, assets: assets})
			}
			continue
		}
		if entry.Find == "" {
			continue
		}
		rule := rewriteRule{
			find:          []byte(entry.Find),
			replace:       []byte(entry.Replace),
			htmlOnly:      entry.In != "text",
			headers:       entry.Headers,
			headerReplace: []byte(strings.NewReplacer("\r\n", " ", "\r", " ", "\n", " ").Replace(entry.Replace)),
		}
		if entry.IgnoreCase {
			rule.pattern = regexp.MustCompile("(?i)" + regexp.QuoteMeta(entry.Find))
		}
		for _, host := range entry.Hosts {
			host = strings.ToLower(host)
			rewrites[host] = append(rewrites[host], rule)
		}
	}
	return rewrites, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_rewrite {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	    max_body <bytes>
//	}
func (rw *Rewrite) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			rw.Endpoint = raw
		case "token":
			rw.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			rw.Interval = caddy.Duration(dur)
		case "max_body":
			n, err := strconv.Atoi(raw)
			if err != nil || n <= 0 {
				return d.Errf("max_body: want a number of bytes, got %q", raw)
			}
			rw.MaxBody = n
		default:
			return d.Errf("unknown nicetry_rewrite option %q", key)
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*Rewrite)(nil)
	_ caddy.Validator             = (*Rewrite)(nil)
	_ caddyhttp.MiddlewareHandler = (*Rewrite)(nil)
	_ caddyfile.Unmarshaler       = (*Rewrite)(nil)
)
