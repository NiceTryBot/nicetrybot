package nicetrycapture

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// tarpitFor serves the given payload's tarpits, with the edge-wide cap given.
func tarpitFor(t *testing.T, payload string, maxHeld int) *Tarpit {
	t.Helper()
	s := newTarpitSet("", "", maxHeld)
	list, err := s.parse([]byte(payload))
	if err != nil {
		t.Fatal(err)
	}
	s.current.Store(list)
	return &Tarpit{set: s}
}

// quick shortens a tarpit's waits to milliseconds, so tests don't sleep for seconds.
func quick(tp *Tarpit, delays []time.Duration, limit time.Duration) {
	for _, pits := range tp.set.current.Load().byHost {
		for _, p := range pits {
			p.delays, p.cap = delays, limit
		}
	}
}

func tarpitRequest(target, ip string) (*http.Request, *caddyhttp.ExtraLogFields) {
	r, vars, extra := lockdownRequest(http.MethodGet, target)
	vars["nicetry_client_ip"] = ip
	return r, extra
}

// countingOrigin stands in for everything further in, answering with the status given.
type countingOrigin struct {
	mu      sync.Mutex
	reached int
	status  int
}

func (o *countingOrigin) handler() caddyhttp.Handler {
	return caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		o.mu.Lock()
		o.reached++
		o.mu.Unlock()
		if o.status != 0 {
			w.WriteHeader(o.status)
			return nil
		}
		return inner(w, r)
	})
}

func (o *countingOrigin) count() int {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.reached
}

const listedClient = `{"tarpits":[{"id":7,"hosts":["Probed.example"],"free":1,"delays":[2,8,24],"cap":30,"max_held":10,
	"when_full":"reject","after":"continue","forget":600,
	"clients":[{"ip":"203.0.113.9","ja4":null,"why":"verdict"}]}]}`

func TestTheTryDecidesTheWaitFreeTriesFirstThenEachDelayThenTheCap(t *testing.T) {
	tp := tarpitFor(t, listedClient, 10)
	p := tp.set.current.Load().byHost["probed.example"][0]

	want := []time.Duration{0, 2 * time.Second, 8 * time.Second, 24 * time.Second, 30 * time.Second, 30 * time.Second}
	for i, w := range want {
		if got := p.delayFor(i + 1); got != w {
			t.Errorf("try %d waits %v, want %v", i+1, got, w)
		}
	}

	p.delays = []time.Duration{40 * time.Second}
	if got := p.delayFor(2); got != 30*time.Second {
		t.Errorf("a delay past the cap waits %v, want the cap", got)
	}
}

func TestAListedClientGetsItsFreeTryThenIsHeldBeforeGoingOn(t *testing.T) {
	tp := tarpitFor(t, listedClient, 10)
	quick(tp, []time.Duration{30 * time.Millisecond}, 50*time.Millisecond)
	o := &countingOrigin{}

	r, extra := tarpitRequest("https://probed.example/phpmyadmin/", "203.0.113.9")
	r.Host = "probed.example"
	w := httptest.NewRecorder()
	start := time.Now()
	if err := tp.ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}
	if time.Since(start) > 20*time.Millisecond || o.count() != 1 {
		t.Fatalf("the free try should go straight on")
	}
	fields := loggedFields(extra)
	if fields["tarpit"] != int64(7) || fields["tarpit_outcome"] != "free" || fields["tarpit_why"] != "verdict" || fields["tarpit_try"] != int64(1) {
		t.Errorf("free try logged %v", fields)
	}

	r, extra = tarpitRequest("https://probed.example/phpmyadmin/", "203.0.113.9")
	r.Host = "probed.example"
	w = httptest.NewRecorder()
	start = time.Now()
	if err := tp.ServeHTTP(w, r, o.handler()); err != nil {
		t.Fatal(err)
	}
	if held := time.Since(start); held < 30*time.Millisecond {
		t.Errorf("the second try was held %v, want at least 30ms", held)
	}
	if o.count() != 2 || w.Code != http.StatusOK {
		t.Errorf("after the wait the request should go on (reached %d, status %d)", o.count(), w.Code)
	}
	fields = loggedFields(extra)
	if fields["tarpit_outcome"] != "held" || fields["tarpit_try"] != int64(2) || fields["tarpit_ms"].(int64) < 30 {
		t.Errorf("held try logged %v", fields)
	}
	if _, ok := fields["action"]; ok {
		t.Errorf("a request that went on to the origin shouldn't log an action, got %v", fields["action"])
	}
}

func TestOtherClientsHostsAndTheGymsOwnPathsAreLeftAlone(t *testing.T) {
	tp := tarpitFor(t, listedClient, 10)
	o := &countingOrigin{}

	for _, c := range []struct{ host, path, ip string }{
		{"probed.example", "/", "198.51.100.1"},
		{"other.example", "/", "203.0.113.9"},
		{"probed.example", "/_q/gym/abc", "203.0.113.9"},
	} {
		for range 3 {
			r, extra := tarpitRequest("https://"+c.host+c.path, c.ip)
			r.Host = c.host
			if err := tp.ServeHTTP(httptest.NewRecorder(), r, o.handler()); err != nil {
				t.Fatal(err)
			}
			if _, ok := loggedFields(extra)["tarpit"]; ok {
				t.Errorf("%s%s from %s shouldn't be tarpitted", c.host, c.path, c.ip)
			}
		}
	}
	if o.count() != 9 {
		t.Errorf("reached %d, want 9", o.count())
	}
}

func TestAFullTarpitAnswers429AtOnce(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":3,"hosts":["probed.example"],"free":0,"delays":[1],"cap":1,"max_held":1,
		"when_full":"reject","after":"continue","forget":600,"clients":[{"ip":"203.0.113.9"},{"ip":"203.0.113.10"}]}]}`, 10)
	quick(tp, []time.Duration{200 * time.Millisecond}, 200*time.Millisecond)
	o := &countingOrigin{}

	started := make(chan struct{})
	done := make(chan struct{})
	go func() {
		r, _ := tarpitRequest("https://probed.example/", "203.0.113.9")
		r.Host = "probed.example"
		close(started)
		tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
		close(done)
	}()
	<-started
	time.Sleep(30 * time.Millisecond)

	r, extra := tarpitRequest("https://probed.example/", "203.0.113.10")
	r.Host = "probed.example"
	w := httptest.NewRecorder()
	start := time.Now()
	tp.ServeHTTP(w, r, o.handler())
	if w.Code != http.StatusTooManyRequests || time.Since(start) > 50*time.Millisecond {
		t.Errorf("a full tarpit answered %d after %v, want an immediate 429", w.Code, time.Since(start))
	}
	fields := loggedFields(extra)
	if fields["tarpit_outcome"] != "full" || fields["action"] != "blocked" {
		t.Errorf("full logged %v", fields)
	}
	<-done
	if o.count() != 1 {
		t.Errorf("only the held request should reach the origin, reached %d", o.count())
	}
}

func TestTheEdgeWideCapCountsAcrossTarpits(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[
		{"id":1,"hosts":["a.example"],"free":0,"delays":[1],"cap":1,"max_held":5,"when_full":"pass","forget":600,"clients":[{"ip":"203.0.113.9"}]},
		{"id":2,"hosts":["b.example"],"free":0,"delays":[1],"cap":1,"max_held":5,"when_full":"pass","forget":600,"clients":[{"ip":"203.0.113.9"}]}]}`, 1)
	quick(tp, []time.Duration{200 * time.Millisecond}, 200*time.Millisecond)
	o := &countingOrigin{}

	done := make(chan struct{})
	go func() {
		r, _ := tarpitRequest("https://a.example/", "203.0.113.9")
		r.Host = "a.example"
		tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
		close(done)
	}()
	time.Sleep(30 * time.Millisecond)

	r, extra := tarpitRequest("https://b.example/", "203.0.113.9")
	r.Host = "b.example"
	start := time.Now()
	tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if time.Since(start) > 50*time.Millisecond || loggedFields(extra)["tarpit_outcome"] != "full" {
		t.Errorf("past the edge's cap, a pass tarpit should let the request straight through: %v", loggedFields(extra))
	}
	<-done
}

func TestTooMany404sPutAClientInTheTarpit(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":4,"hosts":["probed.example"],"free":0,"delays":[1],"cap":1,"max_held":5,
		"when_full":"reject","after":"not_found","forget":600,"clients":[],"not_found":{"count":2,"within":60}}]}`, 10)
	quick(tp, []time.Duration{10 * time.Millisecond}, 10*time.Millisecond)
	o := &countingOrigin{status: http.StatusNotFound}

	var last map[string]any
	for range 3 {
		r, extra := tarpitRequest("https://probed.example/.env", "203.0.113.9")
		r.Host = "probed.example"
		tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
		last = loggedFields(extra)
	}
	if last["tarpit_why"] != "not_found" || last["tarpit_outcome"] != "held" {
		t.Errorf("the third request, after two 404s, logged %v", last)
	}
	if o.count() != 2 {
		t.Errorf("after_wait not_found should answer the 404 itself, origin reached %d times", o.count())
	}
}

func TestABurstPutsAClientInTheTarpitUntilItGoesQuiet(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":5,"hosts":["probed.example"],"free":1,"delays":[1],"cap":1,"max_held":5,
		"when_full":"reject","after":"continue","forget":1,"clients":[],"burst":{"count":3,"within":10}}]}`, 10)
	quick(tp, []time.Duration{5 * time.Millisecond}, 5*time.Millisecond)
	o := &countingOrigin{}

	var whys []any
	for range 5 {
		r, extra := tarpitRequest("https://probed.example/", "203.0.113.9")
		r.Host = "probed.example"
		tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
		whys = append(whys, loggedFields(extra)["tarpit_outcome"])
	}
	want := []any{nil, nil, "free", "held", "held"}
	for i := range want {
		if whys[i] != want[i] {
			t.Errorf("request %d: outcome %v, want %v", i+1, whys[i], want[i])
		}
	}

	p := tp.set.current.Load().byHost["probed.example"][0]
	p.forget = 20 * time.Millisecond
	time.Sleep(40 * time.Millisecond)
	r, extra := tarpitRequest("https://probed.example/", "203.0.113.9")
	r.Host = "probed.example"
	tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if _, ok := loggedFields(extra)["tarpit"]; ok {
		t.Errorf("a client quiet for the forget time should be out again: %v", loggedFields(extra))
	}
}

func TestAHeldRequestEndsWhenItsClientLeaves(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":6,"hosts":["probed.example"],"free":0,"delays":[30],"cap":30,"max_held":5,
		"when_full":"reject","after":"continue","forget":600,"clients":[{"ip":"203.0.113.9"}]}]}`, 10)
	o := &countingOrigin{}

	r, extra := tarpitRequest("https://probed.example/", "203.0.113.9")
	r.Host = "probed.example"
	ctx, cancel := context.WithCancel(r.Context())
	r = r.WithContext(ctx)
	time.AfterFunc(20*time.Millisecond, cancel)
	start := time.Now()
	tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if time.Since(start) > time.Second || o.count() != 0 {
		t.Errorf("a client that left should end the hold at once, and never reach the origin")
	}
	if loggedFields(extra)["tarpit_outcome"] != "left" {
		t.Errorf("logged %v", loggedFields(extra))
	}
	if held := tp.set.held.Load(); held != 0 {
		t.Errorf("%d holds still counted after the request ended", held)
	}
}

func TestASingleRequestRuleHoldsAMatchingRequest(t *testing.T) {
	tp := tarpitFor(t, `{"tarpits":[{"id":8,"hosts":["probed.example"],"free":0,"delays":[1],"cap":1,"max_held":5,
		"when_full":"reject","after":"continue","forget":600,"clients":[],
		"rules":[{"id":12,"expr":{"k":"like","f":"path","p":"/phpmyadmin%","neg":false}}]}]}`, 10)
	quick(tp, []time.Duration{5 * time.Millisecond}, 5*time.Millisecond)
	o := &countingOrigin{}

	r, extra := tarpitRequest("https://probed.example/phpmyadmin/index.php", "203.0.113.9")
	r.Host = "probed.example"
	tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if loggedFields(extra)["tarpit_why"] != "rule" {
		t.Errorf("a request the rule matches should be held: %v", loggedFields(extra))
	}

	r, extra = tarpitRequest("https://probed.example/about", "203.0.113.9")
	r.Host = "probed.example"
	tp.ServeHTTP(httptest.NewRecorder(), r, o.handler())
	if _, ok := loggedFields(extra)["tarpit"]; ok {
		t.Errorf("a request the rule doesn't match should go straight on")
	}
}

func TestCountsSurviveAPollAndAnAnswerWithoutTarpitsIsRefused(t *testing.T) {
	tp := tarpitFor(t, listedClient, 10)
	p := tp.set.current.Load().byHost["probed.example"][0]
	p.try("203.0.113.9", time.Now())

	list, err := tp.set.parse([]byte(listedClient))
	if err != nil {
		t.Fatal(err)
	}
	if got := list.byHost["probed.example"][0].try("203.0.113.9", time.Now()); got != 2 {
		t.Errorf("after a poll the client is on try %d, want 2", got)
	}

	if _, err := tp.set.parse([]byte(`{"triggers":[]}`)); err == nil {
		t.Error("an answer that doesn't list tarpits should be refused")
	}
	if list, err := tp.set.parse([]byte(`{"tarpits":[]}`)); err != nil || len(list.byHost) != 0 {
		t.Errorf("an empty list means no tarpits: %v %v", list, err)
	}
}
