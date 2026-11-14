package nicetrycapture

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// fakeHoneypot stands in for a Galah server, remembering what it was asked.
type fakeHoneypot struct {
	*httptest.Server
	asked *http.Request
	body  string
}

func newFakeHoneypot(t *testing.T, answer func(w http.ResponseWriter)) *fakeHoneypot {
	t.Helper()
	hp := &fakeHoneypot{}
	hp.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		hp.asked, hp.body = r.Clone(context.Background()), string(raw)
		answer(w)
	}))
	t.Cleanup(hp.Close)
	return hp
}

func galahAnswer(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Server", "Apache/2.4.58")
	w.Header().Set("Set-Cookie", "wordpress_test_cookie=WP+Cookie+check")
	w.Header().Set("Content-Encoding", "gzip")
	w.WriteHeader(http.StatusOK)
	_, _ = io.WriteString(w, "<title>Log In</title>")
}

// honeypotHandler is a handler whose list is raw, reaching honeypots on any address (they're local).
func honeypotHandler(t *testing.T, raw string) *Honeypot {
	t.Helper()
	traps, err := parseHoneypots([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	m := &honeypotMap{}
	m.current.Store(&traps)
	return &Honeypot{traps: m, client: newHoneypotClient(true)}
}

func trapsFor(origin string, extra string) string {
	return fmt.Sprintf(`{"honeypots":[{"id":7,"honeypot":3,"hosts":["shop.example"],"origin":%q,"secret":"s3cret","timeout":5,"globs":["/wp-admin*","/api/*"],"regexes":["\\.env$"]%s}]}`, origin, extra)
}

// siteOrigin stands in for the site's origin: a status, a body and a header of its own.
type siteOrigin struct {
	status  int
	body    string
	reached bool
	gotBody string
}

func (o *siteOrigin) handler() caddyhttp.Handler {
	return caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		o.reached = true
		if r.Body != nil {
			raw, _ := io.ReadAll(r.Body)
			o.gotBody = string(raw)
		}
		w.Header().Set("Content-Type", "text/html")
		w.Header().Set("Set-Cookie", "origin_session=1")
		w.WriteHeader(o.status)
		_, _ = io.WriteString(w, o.body)
		return nil
	})
}

// trapRequest is a visitor's request through the handler, with Caddy's vars and log fields, an edge
// header set before the honeypot ran, and the visitor's address resolved.
func trapRequest(t *testing.T, hp *Honeypot, method, target, body string, o *siteOrigin, vars map[string]any) (*httptest.ResponseRecorder, *caddyhttp.ExtraLogFields) {
	t.Helper()
	var reader io.Reader
	if body != "" {
		reader = strings.NewReader(body)
	}
	r := httptest.NewRequest(method, target, reader)
	r.Header.Set("User-Agent", "sqlmap/1.8")
	r.Header.Set("X-NiceTry-Origin-Secret", "visitor-guess")
	r.Header.Set("X-Forwarded-For", "6.6.6.6")
	if vars == nil {
		vars = map[string]any{}
	}
	vars[caddyhttp.ClientIPVarKey] = "203.0.113.9"
	extra := new(caddyhttp.ExtraLogFields)
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	ctx = context.WithValue(ctx, caddyhttp.ExtraLogFieldsCtxKey, extra)
	r = r.WithContext(ctx)

	w := httptest.NewRecorder()
	w.Header().Set("Strict-Transport-Security", "max-age=63072000")
	if err := hp.ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}
	return w, extra
}

func TestATrapped404IsAnsweredByTheHoneypot(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusNotFound, body: "not here"}

	w, extra := trapRequest(t, hp, http.MethodPost, "https://Shop.Example/wp-admin/admin-ajax.php?action=x", "log=a&pwd=b", o, nil)

	if w.Code != http.StatusOK || w.Body.String() != "<title>Log In</title>" {
		t.Fatalf("visitor got %d %q", w.Code, w.Body.String())
	}
	if !o.reached || o.gotBody != "log=a&pwd=b" {
		t.Errorf("the origin should be asked first, with the whole body (got %q)", o.gotBody)
	}
	h := w.Header()
	if h.Get("X-Robots-Tag") != "noindex, nofollow" {
		t.Errorf("X-Robots-Tag %q", h.Get("X-Robots-Tag"))
	}
	if h.Get("Strict-Transport-Security") == "" {
		t.Error("the edge's own headers should stay")
	}
	if strings.Contains(strings.Join(h.Values("Set-Cookie"), ";"), "origin_session") {
		t.Error("the origin's 404 headers leaked into the honeypot's answer")
	}
	if h.Get("Set-Cookie") != "wordpress_test_cookie=WP+Cookie+check" {
		t.Errorf("the honeypot's cookie should go out, got %q", h.Values("Set-Cookie"))
	}
	if h.Get("Server") != "" || h.Get("Content-Encoding") != "" {
		t.Errorf("Server %q and Content-Encoding %q should be the edge's business", h.Get("Server"), h.Get("Content-Encoding"))
	}

	asked := galah.asked
	if asked.Method != http.MethodPost || asked.URL.RequestURI() != "/wp-admin/admin-ajax.php?action=x" || galah.body != "log=a&pwd=b" {
		t.Errorf("honeypot got %s %s %q", asked.Method, asked.URL.RequestURI(), galah.body)
	}
	if asked.Host != strings.TrimPrefix(galah.URL, "http://") {
		t.Errorf("Host %q should be the honeypot's own", asked.Host)
	}
	if asked.Header.Get("X-Forwarded-Host") != "Shop.Example" || asked.Header.Get("X-Forwarded-For") != "203.0.113.9" {
		t.Errorf("forwarded host %q, for %q", asked.Header.Get("X-Forwarded-Host"), asked.Header.Get("X-Forwarded-For"))
	}
	if asked.Header.Get("X-NiceTry-Origin-Secret") != "s3cret" || len(asked.Header.Values("X-NiceTry-Origin-Secret")) != 1 {
		t.Errorf("secret %q: the visitor's guess must not reach the honeypot", asked.Header.Values("X-NiceTry-Origin-Secret"))
	}
	if asked.Header.Get("User-Agent") != "sqlmap/1.8" {
		t.Error("the visitor's own headers should reach the honeypot")
	}

	fields := loggedFields(extra)
	if fields["honeypot"] != int64(3) || fields["honeypot_trap"] != int64(7) || fields["honeypot_status"] != int64(200) || fields["honeypot_outcome"] != "served" {
		t.Errorf("logged %v", fields)
	}
}

func TestAPathTheSiteServesIsNeverTouched(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusOK, body: "the real dashboard"}

	w, extra := trapRequest(t, hp, http.MethodGet, "https://shop.example/wp-admin/", "", o, nil)

	if w.Code != http.StatusOK || w.Body.String() != "the real dashboard" || w.Header().Get("Set-Cookie") != "origin_session=1" {
		t.Errorf("visitor got %d %q %v", w.Code, w.Body.String(), w.Header())
	}
	if galah.asked != nil || len(loggedFields(extra)) != 0 {
		t.Error("the honeypot shouldn't be asked about a page the site serves")
	}
}

func TestA404OffTheTrapsPathsIsTheSites(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))

	for _, target := range []string{"https://shop.example/blog/missing", "https://other.example/wp-admin/"} {
		o := &siteOrigin{status: http.StatusNotFound, body: "not here"}
		w, _ := trapRequest(t, hp, http.MethodGet, target, "", o, nil)
		if w.Code != http.StatusNotFound || w.Body.String() != "not here" {
			t.Errorf("%s: visitor got %d %q", target, w.Code, w.Body.String())
		}
	}
	if galah.asked != nil {
		t.Error("the honeypot was asked about a path no trap covers")
	}
}

func TestGlobsIgnoreCaseAndRegexesMatchAnywhere(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))

	for _, target := range []string{"https://shop.example/WP-ADMIN/", "https://shop.example/api/v1/users", "https://shop.example/backup/.env"} {
		o := &siteOrigin{status: http.StatusNotFound}
		if w, _ := trapRequest(t, hp, http.MethodGet, target, "", o, nil); w.Code != http.StatusOK {
			t.Errorf("%s: visitor got %d, want the honeypot's 200", target, w.Code)
		}
	}
	o := &siteOrigin{status: http.StatusNotFound}
	if w, _ := trapRequest(t, hp, http.MethodGet, "https://shop.example/api", "", o, nil); w.Code != http.StatusNotFound {
		t.Error("/api/* shouldn't cover /api itself")
	}
}

func TestTheSites404GoesOutWhenTheHoneypotCantAnswer(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	galah.Close()
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusNotFound, body: "not here"}

	w, extra := trapRequest(t, hp, http.MethodGet, "https://shop.example/wp-admin/", "", o, nil)

	if w.Code != http.StatusNotFound || w.Body.String() != "not here" || w.Header().Get("Set-Cookie") != "origin_session=1" {
		t.Errorf("visitor got %d %q %v", w.Code, w.Body.String(), w.Header())
	}
	if fields := loggedFields(extra); fields["honeypot_outcome"] != "failed" || fields["honeypot_status"] != int64(0) {
		t.Errorf("logged %v", fields)
	}
}

func TestAnAllowedClientAlwaysGetsTheSites404(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusNotFound, body: "not here"}

	w, _ := trapRequest(t, hp, http.MethodGet, "https://shop.example/wp-admin/", "", o, map[string]any{nicetryAllowedVar: true})

	if w.Code != http.StatusNotFound || galah.asked != nil {
		t.Errorf("an allowed client (a verified search engine) got %d from the honeypot", w.Code)
	}
}

func TestABigBodyGoesToTheOriginOnly(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusNotFound, body: "not here"}
	big := strings.Repeat("a", honeypotMaxRequest+10)

	w, _ := trapRequest(t, hp, http.MethodPost, "https://shop.example/api/upload", big, o, nil)

	if w.Code != http.StatusNotFound || len(o.gotBody) != len(big) || galah.asked != nil {
		t.Errorf("visitor got %d, origin got %d of %d bytes, honeypot asked: %v", w.Code, len(o.gotBody), len(big), galah.asked != nil)
	}
}

func TestAHoneypotOnAPrivateAddressIsNeverReached(t *testing.T) {
	galah := newFakeHoneypot(t, galahAnswer)
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	hp.client = newHoneypotClient(false)
	o := &siteOrigin{status: http.StatusNotFound, body: "not here"}

	w, _ := trapRequest(t, hp, http.MethodGet, "https://shop.example/wp-admin/", "", o, nil)

	if w.Code != http.StatusNotFound || galah.asked != nil {
		t.Errorf("a honeypot on 127.0.0.1 was asked (visitor got %d)", w.Code)
	}
}

func TestAHoneypotsRedirectGoesToTheVisitor(t *testing.T) {
	galah := newFakeHoneypot(t, func(w http.ResponseWriter) {
		w.Header().Set("Location", "/wp-login.php")
		w.WriteHeader(http.StatusFound)
	})
	hp := honeypotHandler(t, trapsFor(galah.URL, ""))
	o := &siteOrigin{status: http.StatusNotFound}

	w, _ := trapRequest(t, hp, http.MethodGet, "https://shop.example/wp-admin/", "", o, nil)

	if w.Code != http.StatusFound || w.Header().Get("Location") != "/wp-login.php" {
		t.Errorf("visitor got %d to %q", w.Code, w.Header().Get("Location"))
	}
}

func TestTrapsTheEdgeCantUseAreSkipped(t *testing.T) {
	raw := `{"honeypots":[
		{"id":1,"honeypot":1,"hosts":["a.example"],"origin":"ftp://x.example","secret":"s","globs":["/x"]},
		{"id":2,"honeypot":1,"hosts":["a.example"],"origin":"https://x.example","secret":"","globs":["/x"]},
		{"id":3,"honeypot":1,"hosts":["a.example"],"origin":"https://x.example","secret":"s","regexes":["(unclosed"]},
		{"id":4,"honeypot":1,"hosts":["A.Example"],"origin":"https://x.example/ignored/path","secret":"s","globs":["/x"],"regexes":["(bad"]}
	]}`
	traps, err := parseHoneypots([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	got := traps["a.example"]
	if len(got) != 1 || got[0].id != 4 || len(got[0].patterns) != 1 || got[0].origin.String() != "https://x.example" {
		t.Fatalf("kept %+v", got)
	}
	if got[0].timeout != honeypotDefaultTimeout {
		t.Errorf("timeout %v", got[0].timeout)
	}
	if _, err := parseHoneypots([]byte(`{}`)); err == nil {
		t.Error("an answer without a honeypots list should be refused, so the last list stays")
	}
}
