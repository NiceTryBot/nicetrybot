package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"regexp"
	"slices"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func TestARequestIsJudgedByItsCleanedPathAndWhatAFrontControllerRoutes(t *testing.T) {
	for sent, want := range map[string][]string{
		"/login":                {"/login"},
		"/admin/":               {"/admin/"},
		"/":                     {"/"},
		"/index.php/login":      {"/index.php/login", "/login"},
		"/_q/../login":          {"/login", "/_q/../login"},
		"//login":               {"/login", "//login"},
		"/a/./b/":               {"/a/b/", "/a/./b/"},
		"/INDEX.PHP/admin/x":    {"/INDEX.PHP/admin/x", "/admin/x"},
		"/index.php/../login":   {"/login", "/index.php/../login"},
		"/app/index.php/login":  {"/app/index.php/login"},
		"/index.php":            {"/index.php"},
		"/index.php/":           {"/index.php/", "/"},
		"/foo//index.php/login": {"/foo/index.php/login", "/foo//index.php/login"},
	} {
		if got := pathsFor(sent); !slices.Equal(got, want) {
			t.Errorf("%s: got %q, want %q", sent, got, want)
		}
	}
}

func TestDotSegmentsAreFoundButEmptySegmentsAreNot(t *testing.T) {
	for p, want := range map[string]bool{
		"/_q/../login": true, "/a/./b": true, "/a/..": true, "/a/.": true, "/..": true,
		"//login": false, "/.env": false, "/a..b/": false, "/...": false, "/": false,
	} {
		if got := hasDotSegments(p); got != want {
			t.Errorf("%s: got %v, want %v", p, got, want)
		}
	}
}

// A trigger on /login* catches a front controller's /index.php/login, and NiceTryBot hears the path the
// trigger matched (it matches its routines to it too).
func TestTheGymCatchesALoginBehindAFrontController(t *testing.T) {
	var uri string
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		uri = r.Header.Get("X-NiceTry-Uri")
		_, _ = w.Write([]byte("gym"))
	}))
	defer check.Close()

	g := gymFor(check.URL)
	g.targets.current.Store(&[]target{{ID: 1, Hosts: map[string]bool{"sign.example": true}, Method: "POST", Path: mustGlob(t, "/login*")}})

	r := withCaddyVars(httptest.NewRequest(http.MethodPost, "https://sign.example/index.php/login?next=%2F", nil))
	o := &origin{}
	w := httptest.NewRecorder()
	_ = g.ServeHTTP(w, r, caddyhttp.HandlerFunc(o.ServeHTTP))
	if o.called || w.Body.String() != "gym" {
		t.Fatalf("expected the gym, got %d %q (origin called: %v)", w.Code, w.Body.String(), o.called)
	}
	if uri != "/login?next=%2F" {
		t.Errorf("NiceTryBot heard %q", uri)
	}
}

// /_q/ only skips the gym once the path is cleaned: /_q/../login is the origin's /login.
func TestAPathClimbingOutOfTheGymsOwnRoutesIsStillJudged(t *testing.T) {
	asked := false
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		asked = true
		_, _ = w.Write([]byte("gym"))
	}))
	defer check.Close()

	g := gymFor(check.URL)
	g.targets.current.Store(&[]target{{ID: 1, Hosts: map[string]bool{"sign.example": true}, Path: mustGlob(t, "/login*")}})

	_, o := serve(g, "https://sign.example/_q/../login")
	if !asked || o.called {
		t.Fatalf("expected the gym check (asked: %v, origin called: %v)", asked, o.called)
	}

	asked = false
	_, o = serve(g, "https://sign.example/_q/login")
	if asked || !o.called {
		t.Fatalf("the gym's own route should go straight on (asked: %v, origin called: %v)", asked, o.called)
	}
}

func TestAPathRuleHoldsOnAnyJudgedPath(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 3, Hosts: map[string]bool{"sign.example": true}, Path: mustGlob(t, "*"), Limited: true,
		Rules: []edgeRule{{ID: 12, Expr: compiled(&ruleExpr{Kind: "like", Field: "path", Pattern: "/login%"})}}}})

	for path, want := range map[string]bool{"/index.php/login": true, "/_q/../login/": true, "/about": false} {
		r := withCaddyVars(httptest.NewRequest(http.MethodPost, "https://sign.example"+path, nil))
		if got := ts.match(r) != nil; got != want {
			t.Errorf("%s: matched %v, want %v", path, got, want)
		}
	}
}

func TestATarpitHoldsALoginBehindAFrontControllerAndOneClimbingOutOfTheGym(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":8,"hosts":["probed.example"],"free":0,"delays":[1],"cap":1,"max_held":5,
		"when_full":"reject","after":"continue","forget":600,"clients":[],
		"rules":[{"id":12,"expr":{"k":"like","f":"path","p":"/login%","neg":false}}]}]}`, 10)
	quick(tp, []time.Duration{time.Millisecond}, time.Millisecond)

	for _, path := range []string{"/index.php/login", "/_q/../login"} {
		r, extra := tarpitRequest("https://probed.example"+path, "203.0.113.9")
		r.Host = "probed.example"
		_ = tp.ServeHTTP(httptest.NewRecorder(), r, (&countingOrigin{}).handler())
		if loggedFields(extra)["tarpit_why"] != "rule" {
			t.Errorf("%s should be held: %v", path, loggedFields(extra))
		}
	}
}

func TestACaptureMatchesAnyJudgedPath(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 7, Hosts: map[string]bool{"sign.example": true}, Method: "POST", Path: GlobRegexp("/login")}})

	r := withCaddyVars(httptest.NewRequest(http.MethodPost, "https://sign.example/index.php/login", nil))
	if len(ts.candidates(r)) != 1 {
		t.Error("a capture on /login should see /index.php/login")
	}
}

func mustGlob(t *testing.T, glob string) *regexp.Regexp {
	t.Helper()
	re, err := compileGlob(glob, true)
	if err != nil {
		t.Fatal(err)
	}
	return re
}

func compiled(e *ruleExpr) *ruleExpr {
	e.compile()
	return e
}
