package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// fakeApp answers the resolve endpoint: new.example is placed, everything else is nobody's.
func fakeApp(t *testing.T, hits *atomic.Int32) *httptest.Server {
	t.Helper()
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		if r.Header.Get("Authorization") != "Bearer t" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		time.Sleep(50 * time.Millisecond)
		if r.URL.Query().Get("host") != "new.example" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_, _ = w.Write([]byte(`{"host":"new.example","scheme":"https","upstream":"sites.example.org:443","server_name":"sites.example.org","host_header":"sites.example.org","forwarded_host_header":"X-Domain","secret":"s","timeout":30}`))
	}))
	t.Cleanup(app.Close)
	return app
}

func TestANewHostIsResolvedOnceForEveryoneAskingAtOnce(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	r := newResolver(app.URL, "t", app.Client())

	var wg sync.WaitGroup
	for range 20 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			route, ok := r.lookup("new.example")
			if !ok || route.ForwardedHostHeader != "X-Domain" {
				t.Errorf("lookup = %+v, %v", route, ok)
			}
		}()
	}
	wg.Wait()

	if _, ok := r.lookup("new.example"); !ok {
		t.Error("the answer should be remembered")
	}
	if got := hits.Load(); got != 1 {
		t.Errorf("the app was asked %d times, want 1", got)
	}
}

func TestAHostNobodyClaimsIsRememberedAndJunkIsNeverSent(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	r := newResolver(app.URL, "t", app.Client())

	for range 3 {
		if _, ok := r.lookup("stranger.example"); ok {
			t.Fatal("stranger.example shouldn't resolve")
		}
	}
	for _, junk := range []string{"10.0.0.1", "localhost", "", "a..example", "UPPER.example"} {
		if _, ok := r.lookup(junk); ok {
			t.Errorf("%q shouldn't resolve", junk)
		}
	}
	if got := hits.Load(); got != 1 {
		t.Errorf("the app was asked %d times, want 1", got)
	}
}

func TestTheHandlerFallsBackToTheResolverForAnUnpolledHost(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	h := originsFor(map[string]originRoute{})
	h.resolver = newResolver(app.URL, "t", app.Client())

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://New.example/", nil))
	if err := h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner)); err != nil {
		t.Fatal(err)
	}
	if vars["nicetry_origin_upstream"] != "sites.example.org:443" || vars["nicetry_origin_fwd"] != "X-Domain" {
		t.Errorf("unexpected vars: %v", vars)
	}
}
