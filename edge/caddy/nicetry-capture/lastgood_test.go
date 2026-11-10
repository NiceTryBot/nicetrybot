package nicetrycapture

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"testing"
	"time"
)

type fakeKV struct {
	values map[string][]byte
	sets   int
	err    error
}

func newFakeKV() *fakeKV { return &fakeKV{values: map[string][]byte{}} }

func (f *fakeKV) Get(_ context.Context, key string) ([]byte, error) {
	if f.err != nil {
		return nil, f.err
	}
	return f.values[key], nil
}

func (f *fakeKV) Set(_ context.Context, key string, value []byte) error {
	if f.err != nil {
		return f.err
	}
	f.sets++
	f.values[key] = value
	return nil
}

func TestASavedListComesBackWithItsAge(t *testing.T) {
	kv := newFakeKV()
	value, err := encodeLastGood([]byte(`{"triggers":[]}`), time.Now().Add(-time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	kv.values["edge/gym"] = value

	body, age, ok := newLastGood(kv, false).restore("gym")

	if !ok || string(body) != `{"triggers":[]}` {
		t.Fatalf("restore = %q, %v", body, ok)
	}
	if age < 59*time.Minute || age > 61*time.Minute {
		t.Fatalf("age = %v, want about an hour", age)
	}
}

func TestSavingUnderTheEdgePrefixRoundTrips(t *testing.T) {
	kv := newFakeKV()
	store := newLastGood(kv, true)

	store.save("origins", []byte("the list"))

	if _, ok := kv.values["edge/origins"]; !ok {
		t.Fatalf("keys = %v, want edge/origins", kv.values)
	}
	if body, _, ok := store.restore("origins"); !ok || string(body) != "the list" {
		t.Fatalf("restore = %q, %v", body, ok)
	}
}

func TestAnEdgeNotToldToWriteLeavesTheCopyAlone(t *testing.T) {
	kv := newFakeKV()

	newLastGood(kv, false).save("gym", []byte("from an unproven build"))

	if kv.sets != 0 {
		t.Fatalf("sets = %d, want none", kv.sets)
	}
}

func TestAnUnchangedListIsNotWrittenAgain(t *testing.T) {
	kv := newFakeKV()
	store := newLastGood(kv, true)

	store.save("gym", []byte("same"))
	store.save("gym", []byte("same"))
	store.save("gym", []byte("changed"))

	if kv.sets != 2 {
		t.Fatalf("sets = %d, want 2", kv.sets)
	}
}

func TestAFailedSaveIsTriedAgainNextPoll(t *testing.T) {
	kv := newFakeKV()
	store := newLastGood(kv, true)

	kv.err = errors.New("redis is down")
	store.save("gym", []byte("list"))
	kv.err = nil
	store.save("gym", []byte("list"))

	if kv.sets != 1 {
		t.Fatalf("sets = %d, want 1", kv.sets)
	}
}

func TestNothingStoredOrUnreadableMeansNoCopy(t *testing.T) {
	kv := newFakeKV()
	kv.values["edge/garbled"] = []byte("not a stored list at all")
	store := newLastGood(kv, false)

	for _, name := range []string{"missing", "garbled"} {
		if _, _, ok := store.restore(name); ok {
			t.Errorf("restore(%q) found a copy", name)
		}
	}

	kv.err = errors.New("redis is down")
	if _, _, ok := store.restore("garbled"); ok {
		t.Error("restore found a copy with Redis down")
	}
}

func TestWithoutRedisTheStoreDoesNothing(t *testing.T) {
	var store *lastGood

	store.save("gym", []byte("list"))

	if _, _, ok := store.restore("gym"); ok {
		t.Fatal("a nil store restored a list")
	}
}

func TestARestartStartsFromTheStoredGymTriggers(t *testing.T) {
	kv := newFakeKV()
	store := newLastGood(kv, true)
	store.save("gym", []byte(`{"triggers":[{"id":7,"hosts":["Shop.example"],"path":"*","clients":[]}]}`))

	ts := &targetSet{}
	ts.current.Store(&[]target{})

	if count, ok := ts.restore(store, "gym", "triggers", true); !ok || count != 1 {
		t.Fatalf("restore = %d, %v", count, ok)
	}
	targets := *ts.current.Load()
	if targets[0].ID != 7 || !targets[0].Hosts["shop.example"] {
		t.Fatalf("targets = %+v", targets[0])
	}
}

func TestAStoredListThatNoLongerParsesLeavesTheTargetsEmpty(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("gym", []byte("this isn't JSON"))

	ts := &targetSet{}
	ts.current.Store(&[]target{})

	if _, ok := ts.restore(store, "gym", "triggers", true); ok {
		t.Fatal("restored a list that doesn't parse")
	}
	if len(*ts.current.Load()) != 0 {
		t.Fatalf("targets = %+v", *ts.current.Load())
	}
}

func TestARestartStartsFromTheStoredVerdicts(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("verdicts", []byte(`{"verdicts":[{"ip":"203.0.113.7","header":"fraud; score=15.53"}]}`))

	m := &verdictMap{}
	m.current.Store(&map[string]string{})

	if !m.restore(store) {
		t.Fatal("nothing restored")
	}
	if got := (*m.current.Load())["203.0.113.7"]; got != "fraud; score=15.53" {
		t.Fatalf("verdict = %q", got)
	}
}

func TestARestartStartsFromTheStoredAllowList(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("allow", []byte(`{"allow":["203.0.113.0/24"]}`))

	l := &allowList{}
	l.current.Store(&[]netip.Prefix{})

	if !l.restore(store) {
		t.Fatal("nothing restored")
	}
	if !l.has("203.0.113.7") {
		t.Fatal("the restored range doesn't cover its client")
	}
}

func TestARestartStartsFromTheStoredTarpits(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("tarpit", []byte(listedClient))

	s := newTarpitSet("", "", 10)

	if !s.restore(store) {
		t.Fatal("nothing restored")
	}
	if len(s.current.Load().byHost["probed.example"]) == 0 {
		t.Fatalf("hosts = %v", s.current.Load().byHost)
	}
}

const storedLockdown = `{"lockdowns":[{"team":1,"hosts":["a.example.com"],"secret":"s"}]}`

func TestANewEdgeStartsFromTheStoredLockdowns(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("lockdowns", []byte(storedLockdown))

	m := &lockdownMap{}
	m.current.Store(&map[string]*lockdown{})

	if !m.restore(store) {
		t.Fatal("nothing restored")
	}
	if (*m.current.Load())["a.example.com"] == nil {
		t.Fatalf("lockdowns = %v", *m.current.Load())
	}
}

func TestAnEdgesOwnLockdownStateBeatsTheStoredCopy(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("lockdowns", []byte(storedLockdown))

	m := &lockdownMap{raw: []byte(`{"lockdowns":[]}`)}
	empty := map[string]*lockdown{}
	m.current.Store(&empty)

	if m.restore(store) {
		t.Fatal("the stored copy replaced this edge's own state")
	}
	if len(*m.current.Load()) != 0 {
		t.Fatalf("lockdowns = %v", *m.current.Load())
	}
}

func TestLockdownsMatchingTheStateFileStillReachTheSharedCopy(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(storedLockdown))
	}))
	defer app.Close()

	kv := newFakeKV()
	store := newLastGood(kv, true)
	shared := sharedLastGood
	sharedLastGood = func() *lastGood { return store }
	t.Cleanup(func() { sharedLastGood = shared })

	m := &lockdownMap{endpoint: app.URL, token: "t", raw: []byte(storedLockdown)}

	if err := m.refresh(app.Client()); err != nil {
		t.Fatal(err)
	}
	if body, _, ok := store.restore("lockdowns"); !ok || string(body) != storedLockdown {
		t.Fatalf("stored = %q, %v", body, ok)
	}
}

func TestARestartStartsFromTheStoredRewrites(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("rewrites", []byte(fooToBar))

	m := &rewriteMap{}
	m.current.Store(&map[string][]rewriteRule{})

	if !m.restore(store) {
		t.Fatal("nothing restored")
	}
	if len(m.lookup("shop.example")) != 1 {
		t.Fatalf("rules = %v", m.lookup("shop.example"))
	}
}

func TestAStoredListThatDoesNotParseIsIgnored(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("allow", []byte(`{"not":"an allow list"}`))

	l := &allowList{}
	empty := []netip.Prefix{}
	l.current.Store(&empty)

	if l.restore(store) {
		t.Fatal("restored a list that doesn't parse")
	}
	if len(*l.current.Load()) != 0 {
		t.Fatalf("ranges = %v", *l.current.Load())
	}
}

func TestARestartStartsFromTheStoredOrigins(t *testing.T) {
	store := newLastGood(newFakeKV(), true)
	store.save("origins", []byte(`{"origins":[{"host":"Shop.example","scheme":"https","upstream":"origin.example:443","host_header":"origin.example"}]}`))

	m := &originMap{}
	m.current.Store(&map[string]originRoute{})

	if count, ok := m.restore(store); !ok || count != 1 {
		t.Fatalf("restore = %d, %v", count, ok)
	}
	if route := (*m.current.Load())["shop.example"]; route.Upstream != "origin.example:443" {
		t.Fatalf("route = %+v", route)
	}
}
