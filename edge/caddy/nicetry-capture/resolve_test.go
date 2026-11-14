package nicetrycapture

import (
	"context"
	"fmt"
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
			route, ok := r.lookup(t.Context(), "new.example")
			if !ok || route.ForwardedHostHeader != "X-Domain" {
				t.Errorf("lookup = %+v, %v", route, ok)
			}
		}()
	}
	wg.Wait()

	if _, ok := r.lookup(t.Context(), "new.example"); !ok {
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
		if _, ok := r.lookup(t.Context(), "stranger.example"); ok {
			t.Fatal("stranger.example shouldn't resolve")
		}
	}
	for _, junk := range []string{"10.0.0.1", "localhost", "", "a..example", "UPPER.example"} {
		if _, ok := r.lookup(t.Context(), junk); ok {
			t.Errorf("%q shouldn't resolve", junk)
		}
	}
	if got := hits.Load(); got != 1 {
		t.Errorf("the app was asked %d times, want 1", got)
	}
}

func TestALookupThatCantGetASlotGivesUpWithoutAskingOrRemembering(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	r := newResolver(app.URL, "t", app.Client())
	for range maxResolving {
		r.slots <- struct{}{}
	}

	started := time.Now()
	if _, ok := r.lookup(t.Context(), "new.example"); ok {
		t.Fatal("new.example shouldn't resolve while every slot is busy")
	}
	if waited := time.Since(started); waited > time.Second {
		t.Errorf("waited %v for a slot", waited)
	}
	if got := hits.Load(); got != 0 {
		t.Errorf("the app was asked %d times, want 0", got)
	}

	for range maxResolving {
		<-r.slots
	}
	if _, ok := r.lookup(t.Context(), "new.example"); !ok {
		t.Error("new.example should resolve once a slot is free")
	}
}

func TestALookupForAVisitorWhoLeftIsNeverSent(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	r := newResolver(app.URL, "t", app.Client())
	for range maxResolving {
		r.slots <- struct{}{}
	}

	ctx, cancel := context.WithCancel(t.Context())
	cancel()
	if _, ok := r.lookup(ctx, "new.example"); ok {
		t.Fatal("a cancelled lookup shouldn't resolve")
	}

	for range maxResolving {
		<-r.slots
	}
	if got := hits.Load(); got != 0 {
		t.Errorf("the app was asked %d times, want 0", got)
	}
	if len(r.answers) != 0 || len(r.inFlight) != 0 {
		t.Errorf("answers = %v, in flight = %v; want both empty", r.answers, r.inFlight)
	}
}

func TestANewHostIsTurnedAwayWhenTooManyAreWaiting(t *testing.T) {
	var hits atomic.Int32
	app := fakeApp(t, &hits)
	r := newResolver(app.URL, "t", app.Client())
	for i := range maxPending {
		r.inFlight[fmt.Sprintf("h%d.example", i)] = &resolveCall{done: make(chan struct{})}
	}

	if _, ok := r.lookup(t.Context(), "new.example"); ok {
		t.Fatal("new.example shouldn't resolve while the queue is full")
	}
	if got := hits.Load(); got != 0 {
		t.Errorf("the app was asked %d times, want 0", got)
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
