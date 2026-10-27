package nicetrycapture

import (
	"io"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

// gymFor builds a handler whose trigger is sign.example + /gymbros*, checking against the given server.
func gymFor(check string) *Gym {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 1, Hosts: map[string]bool{"sign.example": true}, Path: regexp.MustCompile("(?i)" + GlobRegexp("/gymbros*").String())}})

	return &Gym{Check: check, Token: "t", Timeout: caddy.Duration(time.Second), targets: ts, logger: zap.NewNop(),
		client: &http.Client{Timeout: time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
}

type origin struct{ called bool }

func (o *origin) ServeHTTP(w http.ResponseWriter, r *http.Request) error {
	o.called = true
	w.WriteHeader(http.StatusNotFound)
	return nil
}

func serve(g *Gym, url string, cookies ...*http.Cookie) (*httptest.ResponseRecorder, *origin) {
	r := httptest.NewRequest(http.MethodGet, url, nil)
	for _, c := range cookies {
		r.AddCookie(c)
	}
	w := httptest.NewRecorder()
	o := &origin{}
	_ = g.ServeHTTP(w, r, caddyhttp.HandlerFunc(o.ServeHTTP))
	return w, o
}

func TestAPassLetsTheRequestThrough(t *testing.T) {
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusNoContent) }))
	defer check.Close()

	_, o := serve(gymFor(check.URL), "https://sign.example/gymbros")
	if !o.called {
		t.Fatal("expected the origin to answer")
	}
}

func TestTheGymIsServedInPlaceWithOnlyGymCookiesForwarded(t *testing.T) {
	var seen *http.Request
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen = r
		http.SetCookie(w, &http.Cookie{Name: "_q_session", Value: "abc"})
		w.Header().Set("Content-Type", "text/html")
		w.Header().Set("X-Internal", "secret")
		_, _ = io.WriteString(w, "<h1>Spot me</h1>")
	}))
	defer check.Close()

	w, o := serve(gymFor(check.URL), "https://sign.example/GymBros?x=1", &http.Cookie{Name: "laravel_session", Value: "site"}, &http.Cookie{Name: "_q_pass", Value: "p"})

	if o.called || w.Code != 200 || !strings.Contains(w.Body.String(), "Spot me") {
		t.Fatalf("expected the gym in place, got %d %q (origin called: %v)", w.Code, w.Body.String(), o.called)
	}
	if w.Header().Get("X-Internal") != "" || !strings.Contains(w.Header().Get("Set-Cookie"), "_q_session=abc") {
		t.Errorf("unexpected headers: %v", w.Header())
	}
	if seen.Header.Get("X-NiceTry-Uri") != "/GymBros?x=1" || seen.Header.Get("X-NiceTry-Host") != "sign.example" || seen.Header.Get("Authorization") != "Bearer t" {
		t.Errorf("unexpected check headers: %v", seen.Header)
	}
	if _, err := seen.Cookie("laravel_session"); err == nil {
		t.Error("the site's own cookie was forwarded")
	}
	if c, err := seen.Cookie("_q_pass"); err != nil || c.Value != "p" {
		t.Error("the gym pass was not forwarded")
	}
}

func TestUntriggeredAndUnreachableRequestsGoThrough(t *testing.T) {
	calls := 0
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls++; w.WriteHeader(500) }))
	defer check.Close()

	for _, url := range []string{"https://sign.example/pricing", "https://other.example/gymbros", "https://sign.example/_q/abc"} {
		if _, o := serve(gymFor(check.URL), url); !o.called {
			t.Errorf("%s: expected the origin to answer", url)
		}
	}
	if calls != 0 {
		t.Errorf("untriggered requests asked the gym %d times", calls)
	}

	if _, o := serve(gymFor(check.URL), "https://sign.example/gymbros"); !o.called {
		t.Error("a failing gym check should let the request through")
	}
	if _, o := serve(gymFor("http://127.0.0.1:1"), "https://sign.example/gymbros"); !o.called {
		t.Error("an unreachable gym check should let the request through")
	}
}

func TestTriggersLimitedToClientsOnlyMatchThoseClients(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 2, Hosts: map[string]bool{"sign.example": true}, Path: GlobRegexp("*"), Clients: []clientFilter{{IP: "203.0.113.7"}}}})

	tagged := httptest.NewRequest(http.MethodGet, "https://sign.example/dashboard", nil)
	tagged.RemoteAddr = "203.0.113.7:51000"
	other := httptest.NewRequest(http.MethodGet, "https://sign.example/dashboard", nil)
	other.RemoteAddr = "198.51.100.4:51000"

	if ts.match(tagged) == nil {
		t.Error("the tagged client should match")
	}
	if ts.match(other) != nil {
		t.Error("another client should not match")
	}
}

func TestBackgroundRequestHeadersReachTheCheck(t *testing.T) {
	var seen *http.Request
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { seen = r; w.WriteHeader(http.StatusNoContent) }))
	defer check.Close()

	r := httptest.NewRequest(http.MethodPost, "https://sign.example/gymbros", nil)
	r.Header.Set("X-Livewire", "true")
	r.Header.Set("Accept", "text/html, application/xhtml+xml")
	r.Header.Set("Referer", "https://sign.example/dashboard/login")
	_ = gymFor(check.URL).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc((&origin{}).ServeHTTP))

	if seen.Header.Get("X-Livewire") != "true" || seen.Header.Get("Referer") != "https://sign.example/dashboard/login" || !strings.Contains(seen.Header.Get("Accept"), "text/html") {
		t.Errorf("headers not forwarded: %v", seen.Header)
	}
}

func TestAProvisionalPassReportsTheOriginsStatus(t *testing.T) {
	reported := make(chan *http.Request, 1)
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/report" {
			reported <- r
			w.WriteHeader(http.StatusNoContent)
			return
		}
		w.Header().Set("X-NiceTry-Report", "1")
		w.WriteHeader(http.StatusNoContent)
	}))
	defer app.Close()

	g := gymFor(app.URL + "/check")
	g.Report = app.URL + "/report"
	_, o := serve(g, "https://sign.example/gymbros", &http.Cookie{Name: "_q_pass", Value: "p"})

	if !o.called {
		t.Fatal("the origin should answer a provisional request")
	}
	select {
	case got := <-reported:
		if got.Header.Get("X-NiceTry-Status") != "404" || got.Header.Get("X-NiceTry-Uri") != "/gymbros" {
			t.Errorf("unexpected report: %v", got.Header)
		}
		if c, err := got.Cookie("_q_pass"); err != nil || c.Value != "p" {
			t.Error("the pass cookie should travel with the report")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("no report arrived")
	}
}

func TestAGymCookieKeepsATaggedBrowserInViewWhenItsFingerprintChanges(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 2, Hosts: map[string]bool{"sign.example": true}, Path: GlobRegexp("*"), Clients: []clientFilter{{IP: "203.0.113.7", JA4: "t13d1517h2_8daaf6152771_cb7bf5808d99"}}}})

	resumed := httptest.NewRequest(http.MethodPost, "https://sign.example/livewire/message/login", nil)
	resumed.RemoteAddr = "203.0.113.7:51000"
	if ts.match(resumed) != nil {
		t.Fatal("without a gym cookie a different fingerprint should not match")
	}

	resumed.AddCookie(&http.Cookie{Name: "_q_pass", Value: "p"})
	if ts.match(resumed) == nil {
		t.Error("the gym cookie should keep the browser in view")
	}
}
