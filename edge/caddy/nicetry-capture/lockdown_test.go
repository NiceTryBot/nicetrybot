package nicetrycapture

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

const lockdownSecret = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef" // gitleaks:allow (a made-up test secret)

func lockdownFor(locked map[string]*lockdown) *Lockdown {
	m := &lockdownMap{}
	m.current.Store(&locked)
	return &Lockdown{lockdowns: m}
}

// lockedSite is a.example.com under lockdown, with an allow list and a secret to sign passes with.
func lockedSite(t *testing.T) *Lockdown {
	t.Helper()
	locked, err := parseLockdowns([]byte(`{"lockdowns":[{"team":12,
		"hosts":["A.example.com","www.a.example.com"],
		"allow":["203.0.113.4","198.51.100.0/24","2001:db8::/48"],
		"secret":"` + lockdownSecret + `",
		"html":"<p>down for maintenance</p>",
		"retry_after":120}]}`))
	if err != nil {
		t.Fatal(err)
	}
	return lockdownFor(locked)
}

func lockdownRequest(method, target string) (*http.Request, map[string]any, *caddyhttp.ExtraLogFields) {
	r := httptest.NewRequest(method, target, strings.NewReader("payload=1"))
	r.RemoteAddr = "192.0.2.50:4711"
	vars := map[string]any{}
	extra := new(caddyhttp.ExtraLogFields)
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	ctx = context.WithValue(ctx, caddyhttp.ExtraLogFieldsCtxKey, extra)
	return r.WithContext(ctx), vars, extra
}

// lockedOrigin stands in for everything further in, remembering what it was handed.
type lockedOrigin struct {
	reached bool
	cookies []string
}

func (o *lockedOrigin) handler() caddyhttp.Handler {
	return caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		o.reached = true
		o.cookies = r.Header.Values("Cookie")
		return inner(w, r)
	})
}

func passLink(secret, host string, linkExp, passExp int64) string {
	l, p := strconv.FormatInt(linkExp, 10), strconv.FormatInt(passExp, 10)
	return "/_q/pass?t=" + l + "." + p + "." + lockdownMAC(secret, "link", host, l, p)
}

func passCookie(secret, host string, passExp int64) string {
	p := strconv.FormatInt(passExp, 10)
	return lockdownPassCookie + "=" + p + "." + lockdownMAC(secret, "pass", host, p)
}

func actionLogged(extra *caddyhttp.ExtraLogFields) string {
	action, _ := loggedFields(extra)["action"].(string)
	return action
}

func assertMaintenance(t *testing.T, w *httptest.ResponseRecorder, o *lockedOrigin, body string) {
	t.Helper()
	if w.Code != http.StatusServiceUnavailable {
		t.Errorf("status = %d, want 503", w.Code)
	}
	if got := w.Body.String(); got != body {
		t.Errorf("body = %q, want %q", got, body)
	}
	if got := w.Header().Get("Retry-After"); got != "120" {
		t.Errorf("Retry-After = %q", got)
	}
	if got := w.Header().Get("Content-Type"); got != "text/html; charset=utf-8" {
		t.Errorf("Content-Type = %q", got)
	}
	if got := w.Header().Get("Cache-Control"); got != "no-store" {
		t.Errorf("Cache-Control = %q", got)
	}
	if o.reached {
		t.Error("the origin was reached")
	}
}

// The MACs the app's PHP must produce for the same inputs.
func TestLockdownMACsMatchTheKnownAnswers(t *testing.T) {
	if got := lockdownMAC("s3cret", "link", "a.example.com", "1900000000", "1900003600"); got != "67b47e211b759ee5e6683718ad0efe227bd07866f48f1b0be55e56eaf246909f" {
		t.Errorf("link mac = %s", got)
	}
	if got := lockdownMAC("s3cret", "pass", "a.example.com", "1900003600"); got != "cb6713002602e8e5b6931ba4b76012bf202313d2b603e0ed8bee5ae43853fb75" {
		t.Errorf("pass mac = %s", got)
	}
}

func TestAnUnlockedHostPassesThrough(t *testing.T) {
	o := &lockedOrigin{}
	r, _, _ := lockdownRequest(http.MethodGet, "https://b.example.com/")
	w := httptest.NewRecorder()
	if err := lockedSite(t).ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}
	if !o.reached || w.Code != http.StatusOK {
		t.Errorf("an unlocked host should reach the origin, got %d", w.Code)
	}
}

func TestEveryRequestToALockedHostGetsTheMaintenancePage(t *testing.T) {
	h := lockedSite(t)
	for _, method := range []string{http.MethodGet, http.MethodPost, http.MethodPut, http.MethodDelete} {
		o := &lockedOrigin{}
		r, vars, extra := lockdownRequest(method, "https://WWW.a.example.com:443/wp-login.php")
		w := httptest.NewRecorder()
		if err := h.ServeHTTP(w, r, o.handler()); err != nil {
			t.Fatal(err)
		}
		assertMaintenance(t, w, o, "<p>down for maintenance</p>")
		if vars["bot_tag"] != "lockdown" || actionLogged(extra) != "blocked" {
			t.Errorf("%s: logged bot_tag %v, action %q", method, vars["bot_tag"], actionLogged(extra))
		}
	}
}

func TestAHeadRequestGetsNoBody(t *testing.T) {
	o := &lockedOrigin{}
	r, _, _ := lockdownRequest(http.MethodHead, "https://a.example.com/")
	w := httptest.NewRecorder()
	_ = lockedSite(t).ServeHTTP(w, r, o.handler())
	assertMaintenance(t, w, o, "")
}

func TestAGoodPassLinkSetsTheCookieAndRedirects(t *testing.T) {
	now := time.Now().Unix()
	passExp := now + 3600
	o := &lockedOrigin{}
	r, vars, extra := lockdownRequest(http.MethodGet, "https://a.example.com"+passLink(lockdownSecret, "a.example.com", now+300, passExp))
	w := httptest.NewRecorder()
	if err := lockedSite(t).ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}

	if w.Code != http.StatusFound || w.Header().Get("Location") != "/" || w.Header().Get("Cache-Control") != "no-store" {
		t.Fatalf("got %d to %q (Cache-Control %q)", w.Code, w.Header().Get("Location"), w.Header().Get("Cache-Control"))
	}
	if o.reached {
		t.Error("the origin was reached")
	}
	cookies := w.Result().Cookies()
	if len(cookies) != 1 {
		t.Fatalf("want one cookie, got %v", cookies)
	}
	c := cookies[0]
	want := strings.TrimPrefix(passCookie(lockdownSecret, "a.example.com", passExp), lockdownPassCookie+"=")
	if c.Name != lockdownPassCookie || c.Value != want || c.Path != "/" || !c.Secure || !c.HttpOnly || c.SameSite != http.SameSiteLaxMode || c.Expires.Unix() != passExp {
		t.Errorf("unexpected cookie: %+v", c)
	}
	if vars["bot_tag"] != "lockdown_pass" || actionLogged(extra) != "passed" {
		t.Errorf("logged bot_tag %v, action %q", vars["bot_tag"], actionLogged(extra))
	}
}

func TestABadPassLinkGetsTheMaintenancePage(t *testing.T) {
	now := time.Now().Unix()
	good := passLink(lockdownSecret, "a.example.com", now+300, now+3600)
	flipped := "0"
	if strings.HasSuffix(good, "0") {
		flipped = "1"
	}
	links := map[string]string{
		"expired link":     passLink(lockdownSecret, "a.example.com", now-1, now+3600),
		"expired pass":     passLink(lockdownSecret, "a.example.com", now+300, now-1),
		"over a week":      passLink(lockdownSecret, "a.example.com", now+300, now+8*24*3600),
		"other host":       passLink(lockdownSecret, "www.a.example.com", now+300, now+3600),
		"other secret":     passLink(strings.Repeat("f", 64), "a.example.com", now+300, now+3600),
		"tampered pass":    strings.Replace(good, "."+strconv.FormatInt(now+3600, 10)+".", "."+strconv.FormatInt(now+7200, 10)+".", 1),
		"tampered mac":     good[:len(good)-1] + flipped,
		"no token":         "/_q/pass",
		"malformed token":  "/_q/pass?t=garbage",
		"uppercase mac":    good[:len(good)-64] + strings.ToUpper(good[len(good)-64:]),
		"post to the link": "",
	}
	h := lockedSite(t)
	for name, link := range links {
		method := http.MethodGet
		if name == "post to the link" {
			method, link = http.MethodPost, good
		}
		o := &lockedOrigin{}
		r, vars, _ := lockdownRequest(method, "https://a.example.com"+link)
		w := httptest.NewRecorder()
		_ = h.ServeHTTP(w, r, o.handler())
		if w.Code != http.StatusServiceUnavailable || len(w.Result().Cookies()) != 0 || o.reached || vars["bot_tag"] != "lockdown" {
			t.Errorf("%s: got %d, cookies %v, reached %v", name, w.Code, w.Result().Cookies(), o.reached)
		}
	}
}

func TestAGoodPassCookieGetsThroughButTheOriginNeverSeesIt(t *testing.T) {
	o := &lockedOrigin{}
	r, vars, _ := lockdownRequest(http.MethodPost, "https://a.example.com/wp-admin/")
	r.Header.Add("Cookie", "session=abc; "+passCookie(lockdownSecret, "a.example.com", time.Now().Unix()+600)+"; theme=dark")
	r.Header.Add("Cookie", passCookie(lockdownSecret, "a.example.com", time.Now().Unix()+600))
	w := httptest.NewRecorder()
	if err := lockedSite(t).ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}
	if !o.reached || w.Code != http.StatusOK {
		t.Fatalf("a pass should reach the origin, got %d", w.Code)
	}
	if len(o.cookies) != 1 || o.cookies[0] != "session=abc; theme=dark" {
		t.Errorf("the origin saw cookies %q", o.cookies)
	}
	if vars["bot_tag"] != "lockdown_pass" {
		t.Errorf("bot_tag = %v", vars["bot_tag"])
	}
}

func TestAPassCookieAloneLeavesNoCookieHeader(t *testing.T) {
	o := &lockedOrigin{}
	r, _, _ := lockdownRequest(http.MethodGet, "https://a.example.com/")
	r.Header.Set("Cookie", passCookie(lockdownSecret, "a.example.com", time.Now().Unix()+600))
	_ = lockedSite(t).ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if !o.reached || o.cookies != nil {
		t.Errorf("reached %v, the origin saw cookies %q", o.reached, o.cookies)
	}
}

func TestAnExpiredOrOtherHostsPassCookieIsBlocked(t *testing.T) {
	now := time.Now().Unix()
	for name, cookie := range map[string]string{
		"expired":    passCookie(lockdownSecret, "a.example.com", now-1),
		"other host": passCookie(lockdownSecret, "b.example.com", now+600),
		"forged":     lockdownPassCookie + "=" + strconv.FormatInt(now+600, 10) + "." + strings.Repeat("0", 64),
		"no mac":     lockdownPassCookie + "=" + strconv.FormatInt(now+600, 10),
	} {
		o := &lockedOrigin{}
		r, _, _ := lockdownRequest(http.MethodGet, "https://a.example.com/")
		r.Header.Set("Cookie", cookie)
		w := httptest.NewRecorder()
		_ = lockedSite(t).ServeHTTP(w, r, o.handler())
		if w.Code != http.StatusServiceUnavailable || o.reached {
			t.Errorf("%s: got %d, reached %v", name, w.Code, o.reached)
		}
	}
}

func TestALockdownWithoutASecretIssuesNoPasses(t *testing.T) {
	locked, err := parseLockdowns([]byte(`{"lockdowns":[{"team":1,"hosts":["a.example.com"],"secret":""}]}`))
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now().Unix()
	o := &lockedOrigin{}
	r, _, _ := lockdownRequest(http.MethodGet, "https://a.example.com"+passLink("", "a.example.com", now+300, now+3600))
	r.Header.Set("Cookie", passCookie("", "a.example.com", now+600))
	w := httptest.NewRecorder()
	_ = lockdownFor(locked).ServeHTTP(w, r, o.handler())
	if w.Code != http.StatusServiceUnavailable || o.reached {
		t.Errorf("got %d, reached %v", w.Code, o.reached)
	}
}

func TestAllowlistedAddressesGetThrough(t *testing.T) {
	h := lockedSite(t)
	cases := []struct {
		remote, clientVar string
		want              bool
	}{
		{"203.0.113.4:5000", "", true},          // bare IPv4
		{"203.0.113.5:5000", "", false},         // its neighbour
		{"198.51.100.77:5000", "", true},        // IPv4 range
		{"[2001:db8:0:ffff::1]:5000", "", true}, // IPv6 range
		{"[2001:db9::1]:5000", "", false},       // outside it
		{"[::ffff:203.0.113.4]:5000", "", true}, // IPv4-mapped
		// nicetry_client's answer (the visitor behind Cloudflare) wins over the connection.
		{"203.0.113.4:5000", "192.0.2.9", false},
		{"162.158.1.1:5000", "198.51.100.1", true},
	}
	for _, c := range cases {
		o := &lockedOrigin{}
		r, vars, _ := lockdownRequest(http.MethodGet, "https://a.example.com/")
		r.RemoteAddr = c.remote
		if c.clientVar != "" {
			vars["nicetry_client_ip"] = c.clientVar
		}
		w := httptest.NewRecorder()
		_ = h.ServeHTTP(w, r, o.handler())
		if o.reached != c.want {
			t.Errorf("%s (client %q): reached = %v, want %v", c.remote, c.clientVar, o.reached, c.want)
		}
		if c.want && vars["bot_tag"] != "lockdown_allow" {
			t.Errorf("%s: bot_tag = %v", c.remote, vars["bot_tag"])
		}
	}
}

func TestParsingFillsDefaultsAndSkipsBadAllowEntries(t *testing.T) {
	locked, err := parseLockdowns([]byte(`{"lockdowns":[{"team":3,"hosts":["Shop.example"],"allow":["10.0.0.0/33","nope","10.1.2.3","2001:db8::1"],"secret":"s","html":"","retry_after":0}]}`))
	if err != nil {
		t.Fatal(err)
	}
	entry := locked["shop.example"]
	if entry == nil {
		t.Fatalf("shop.example isn't locked: %v", locked)
	}
	if string(entry.html) != lockdownFallbackHTML || !strings.Contains(lockdownFallbackHTML, "We're performing scheduled maintenance and will be back shortly.") {
		t.Errorf("want the fallback page, got %q", entry.html)
	}
	if entry.retryAfter != "3600" {
		t.Errorf("retryAfter = %q", entry.retryAfter)
	}
	if fmt.Sprint(entry.allow) != "[10.1.2.3/32 2001:db8::1/128]" {
		t.Errorf("allow = %v", entry.allow)
	}

	if _, err := parseLockdowns([]byte(`{"sites":[]}`)); err == nil {
		t.Error("an answer without a lockdowns list should be refused")
	}
}

func TestRefreshKeepsTheLastListUntilTheAppSaysOtherwise(t *testing.T) {
	var answer atomic.Value
	answer.Store("locked")
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer t" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		switch answer.Load() {
		case "locked":
			if r.Header.Get("If-None-Match") == `"v1"` {
				w.WriteHeader(http.StatusNotModified)
				return
			}
			w.Header().Set("ETag", `"v1"`)
			_, _ = w.Write([]byte(`{"lockdowns":[{"team":1,"hosts":["a.example.com"],"secret":"s"}]}`))
		case "broken":
			w.WriteHeader(http.StatusInternalServerError)
		case "garbage":
			_, _ = w.Write([]byte(`<html>oops</html>`))
		case "empty":
			_, _ = w.Write([]byte(`{"lockdowns":[]}`))
		}
	}))
	defer app.Close()

	state := filepath.Join(t.TempDir(), "lockdown.json")
	m := &lockdownMap{endpoint: app.URL, token: "t", state: state}
	m.current.Store(&map[string]*lockdown{})
	isLocked := func() bool { _, ok := (*m.current.Load())["a.example.com"]; return ok }

	if err := m.refresh(app.Client()); err != nil || !isLocked() || m.etag != `"v1"` {
		t.Fatalf("first answer: err %v, locked %v, etag %q", err, isLocked(), m.etag)
	}
	if err := m.refresh(app.Client()); err != nil || !isLocked() {
		t.Errorf("a 304 should keep the list: err %v", err)
	}
	for _, bad := range []string{"broken", "garbage"} {
		answer.Store(bad)
		if err := m.refresh(app.Client()); err == nil || !isLocked() {
			t.Errorf("%s: err %v, locked %v", bad, err, isLocked())
		}
	}
	answer.Store("empty")
	if err := m.refresh(app.Client()); err != nil || isLocked() || len(*m.current.Load()) != 0 {
		t.Errorf("an empty list should unlock everything: err %v", err)
	}
	saved, err := os.ReadFile(state)
	if err != nil || string(saved) != `{"lockdowns":[]}` {
		t.Errorf("the state file should hold the last answer, got %q, %v", saved, err)
	}
}

func TestTheStateFileSurvivesARestart(t *testing.T) {
	state := filepath.Join(t.TempDir(), "lockdown.json")
	payload := []byte(`{"lockdowns":[{"team":1,"hosts":["a.example.com"],"allow":["203.0.113.4"],"secret":"s","retry_after":60}]}`)

	before := &lockdownMap{state: state}
	locked, err := parseLockdowns(payload)
	if err != nil {
		t.Fatal(err)
	}
	before.current.Store(&locked)
	before.raw = payload
	before.save()

	info, err := os.Stat(state)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("state mode = %v", info.Mode().Perm())
	}
	if leftovers, _ := filepath.Glob(filepath.Join(filepath.Dir(state), ".lockdown.json.*")); len(leftovers) != 0 {
		t.Errorf("temp files left behind: %v", leftovers)
	}

	after := &lockdownMap{state: state}
	after.current.Store(&map[string]*lockdown{})
	after.load()
	entry := (*after.current.Load())["a.example.com"]
	if entry == nil || entry.retryAfter != "60" || !entry.allows("203.0.113.4") {
		t.Errorf("the restarted poller lost the lockdown: %+v", entry)
	}

	missing := &lockdownMap{state: filepath.Join(t.TempDir(), "absent.json")}
	missing.current.Store(&map[string]*lockdown{})
	missing.load()
	if len(*missing.current.Load()) != 0 {
		t.Error("a missing state file should leave nothing locked")
	}
}
