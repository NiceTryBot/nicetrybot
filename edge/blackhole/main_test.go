package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
	"testing"
	"time"
)

var testNow = time.Unix(1_800_000_000, 0)

func entry(address string, in time.Duration) FeedEntry {
	return FeedEntry{Address: address, ExpiresAt: testNow.Add(in).Unix()}
}

func prefix(value string) netip.Prefix {
	parsed, err := parseAddress(value)
	if err != nil {
		panic(err)
	}
	return parsed
}

func TestPlanRefusesWhatMustNeverBeDropped(t *testing.T) {
	refused := []string{
		"10.1.2.3", "192.168.0.0/24", "127.0.0.1", "169.254.169.254", "fe80::1", "::1", "not-an-ip",
		"0.0.0.0/0", "8.0.0.0/8", "::/0", "2001:db8::/16",
	}
	feed := Feed{Mode: "enforce", Protect: []string{"173.245.48.0/20"}, Entries: []FeedEntry{entry("173.245.48.9", time.Hour)}}
	for _, address := range refused {
		feed.Entries = append(feed.Entries, entry(address, time.Hour))
	}

	want := plan(feed, testNow)

	if len(want.entries) != 0 {
		t.Fatalf("applied %v, wanted none", want.entries)
	}
	if len(want.skipped) != len(refused)+1 {
		t.Fatalf("skipped %v, wanted all %d named", want.skipped, len(refused)+1)
	}
}

func TestPlanTakesWidestOfOverlappingEntries(t *testing.T) {
	want := plan(Feed{Mode: "enforce", Entries: []FeedEntry{
		entry("198.51.100.7", time.Hour),
		entry("198.51.100.0/24", time.Minute*10),
		entry("203.0.113.9", time.Hour),
		entry("203.0.113.9", time.Hour*2),
	}}, testNow)

	if len(want.entries) != 2 {
		t.Fatalf("entries = %v", want.entries)
	}
	if _, ok := want.entries[prefix("198.51.100.0/24")]; !ok {
		t.Fatalf("the range should have swallowed the address inside it: %v", want.entries)
	}
	if got := want.entries[prefix("203.0.113.9")]; !got.Equal(testNow.Add(time.Hour * 2)) {
		t.Fatalf("a repeated address should keep its later expiry, got %v", got)
	}
}

func TestPlanLeavesOutExpiredEntriesAndNormalizes(t *testing.T) {
	want := plan(Feed{Entries: []FeedEntry{
		entry("203.0.113.1", -time.Hour),
		entry("203.0.113.2/32", time.Hour),
		entry("::ffff:203.0.113.3", time.Hour),
		entry("198.51.100.77/24", time.Hour),
	}}, testNow)

	for _, expected := range []string{"203.0.113.2", "203.0.113.3", "198.51.100.0/24"} {
		if _, ok := want.entries[prefix(expected)]; !ok {
			t.Errorf("missing %s in %v", expected, want.entries)
		}
	}
	if len(want.entries) != 3 {
		t.Fatalf("entries = %v", want.entries)
	}
}

func TestScriptDropsOnlyWhenEnforcingAndOnlyOnWebPorts(t *testing.T) {
	enforcing := script(plan(Feed{Mode: "enforce", Entries: []FeedEntry{entry("203.0.113.9", time.Hour)}}, testNow), nil, "80, 443")
	observing := script(plan(Feed{Mode: "observe", Entries: []FeedEntry{entry("203.0.113.9", time.Hour)}}, testNow), nil, "80, 443")
	unknown := script(plan(Feed{Mode: "", Entries: []FeedEntry{entry("203.0.113.9", time.Hour)}}, testNow), nil, "80, 443")

	if !strings.Contains(enforcing, "th dport { 80, 443 } ip saddr @blocked4 drop") {
		t.Errorf("enforce should drop:\n%s", enforcing)
	}
	if !strings.Contains(enforcing, "ip saddr @protected4 accept") {
		t.Errorf("protected addresses should be accepted first:\n%s", enforcing)
	}
	for _, text := range []string{observing, unknown} {
		if strings.Contains(text, "drop") {
			t.Errorf("only enforce may drop:\n%s", text)
		}
		if !strings.Contains(text, "@blocked4\n") {
			t.Errorf("observe should still count:\n%s", text)
		}
	}
}

func TestScriptChangesOnlyWhatChanged(t *testing.T) {
	current := map[netip.Prefix]element{
		prefix("203.0.113.1"): {expires: time.Now().Add(time.Hour)},
		prefix("203.0.113.2"): {expires: time.Now().Add(time.Hour)},
		prefix("203.0.113.3"): {expires: time.Now().Add(time.Hour)},
	}
	want := desired{entries: map[netip.Prefix]time.Time{
		prefix("203.0.113.1"):  time.Now().Add(time.Hour),
		prefix("203.0.113.2"):  time.Now().Add(time.Hour * 5),
		prefix("2001:db8::1"):  time.Now().Add(time.Hour),
		prefix("203.0.113.40"): time.Now().Add(time.Hour),
	}}

	text := script(want, current, "80, 443")

	if strings.Contains(text, "203.0.113.1 ") || strings.Contains(text, "203.0.113.1,") {
		t.Errorf("an unchanged element should be left alone, or its counter resets:\n%s", text)
	}
	for _, expected := range []string{
		"delete element inet nicetry_blackhole blocked4 { 203.0.113.2, 203.0.113.3 }",
		"add element inet nicetry_blackhole blocked6 { 2001:db8::1 timeout",
	} {
		if !strings.Contains(text, expected) {
			t.Errorf("missing %q in:\n%s", expected, text)
		}
	}
	if !strings.Contains(text, "203.0.113.2 timeout") || !strings.Contains(text, "203.0.113.40 timeout") {
		t.Errorf("a new address and a lengthened one should be added with timeouts:\n%s", text)
	}
}

func TestParseSetReadsSingleAddressesAndRanges(t *testing.T) {
	output := `{"nftables":[{"metainfo":{"version":"1.1.3"}},{"set":{"name":"blocked4","elem":[
		{"elem":{"val":{"prefix":{"addr":"198.51.100.0","len":24}},"timeout":300,"expires":299,"counter":{"packets":7,"bytes":420}}},
		{"elem":{"val":"203.0.113.9","timeout":3600,"expires":3599,"counter":{"packets":0,"bytes":0}}}
	]}}]}`

	elements, err := parseSet([]byte(output), testNow)
	if err != nil {
		t.Fatal(err)
	}
	if got := elements[prefix("198.51.100.0/24")]; got.packets != 7 || got.bytes != 420 || !got.expires.Equal(testNow.Add(299*time.Second)) {
		t.Errorf("range = %+v", got)
	}
	if _, ok := elements[prefix("203.0.113.9")]; !ok {
		t.Errorf("address missing: %v", elements)
	}
}

// fakeNft holds a kernel's worth of state as JSON listings, and records what it's told.
type fakeNft struct {
	listing map[string]string
	scripts []string
	deleted bool
	fail    error
}

func (f *fakeNft) run(_ context.Context, stdin string, args ...string) ([]byte, error) {
	joined := strings.Join(args, " ")
	switch {
	case strings.HasPrefix(joined, "-j list set"):
		if f.listing == nil {
			return nil, errors.New("no such table")
		}
		return []byte(f.listing[args[len(args)-1]]), nil
	case joined == "-f -":
		if f.fail != nil {
			return nil, f.fail
		}
		f.scripts = append(f.scripts, stdin)
		if f.listing == nil {
			f.listing = map[string]string{"blocked4": `{"nftables":[]}`, "blocked6": `{"nftables":[]}`}
		}
	case strings.HasPrefix(joined, "delete table"):
		f.deleted = true
		f.listing = nil
	}
	return nil, nil
}

func feedServer(t *testing.T, answer func(w http.ResponseWriter, r *http.Request)) (*httptest.Server, *[]map[string]any) {
	t.Helper()
	reports := &[]map[string]any{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer token" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		if r.URL.Path == "/caddy/blackhole/report" {
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			*reports = append(*reports, body)
			w.WriteHeader(http.StatusNoContent)
			return
		}
		answer(w, r)
	}))
	t.Cleanup(server.Close)
	return server, reports
}

func TestUnreachableAppLeavesTheTableAlone(t *testing.T) {
	nft := &fakeNft{listing: map[string]string{"blocked4": `{"nftables":[]}`, "blocked6": `{"nftables":[]}`}}
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusBadGateway) })
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())

	if nft.deleted || len(nft.scripts) != 0 {
		t.Fatalf("a failing app must change nothing before the first good answer: deleted=%v scripts=%d", nft.deleted, len(nft.scripts))
	}
	if !strings.Contains(a.lastError, "502") {
		t.Fatalf("lastError = %q", a.lastError)
	}
}

func TestLastListStandsWhenTheAppGoesAway(t *testing.T) {
	nft := &fakeNft{}
	healthy := true
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) {
		if !healthy {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		_ = json.NewEncoder(w).Encode(Feed{Mode: "enforce", Entries: []FeedEntry{{Address: "203.0.113.9", ExpiresAt: time.Now().Add(time.Hour).Unix()}}})
	})
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())
	healthy = false
	a.tick(context.Background())

	if nft.deleted {
		t.Fatal("an unreachable app must not lift the list")
	}
	if got := nft.scripts[len(nft.scripts)-1]; !strings.Contains(got, "203.0.113.9") || !strings.Contains(got, "drop") {
		t.Fatalf("the last list should keep applying:\n%s", got)
	}
}

func TestAddonGoneRemovesTheTable(t *testing.T) {
	nft := &fakeNft{}
	gone := false
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) {
		if gone {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_ = json.NewEncoder(w).Encode(Feed{Mode: "enforce", Entries: []FeedEntry{{Address: "203.0.113.9", ExpiresAt: time.Now().Add(time.Hour).Unix()}}})
	})
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())
	gone = true
	a.tick(context.Background())

	if !nft.deleted {
		t.Fatal("a 404 means the add-on is off: the table has to go")
	}
	if a.feed != nil {
		t.Fatal("no list should remain")
	}
}

func TestAnUnchangedListIsNotAppliedAgainUnlessTheTableWentAway(t *testing.T) {
	nft := &fakeNft{}
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) {
		_ = json.NewEncoder(w).Encode(Feed{Mode: "observe", Entries: []FeedEntry{}})
	})
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())
	a.tick(context.Background())
	if len(nft.scripts) != 1 {
		t.Fatalf("applied %d times, wanted once", len(nft.scripts))
	}

	nft.listing = nil
	a.tick(context.Background())
	if len(nft.scripts) != 2 {
		t.Fatalf("a table someone removed should come back, applied %d times", len(nft.scripts))
	}
}

func TestFeedWithoutAListIsAFailureNotAnEmptyList(t *testing.T) {
	nft := &fakeNft{}
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte(`{"mode":"enforce"}`)) })
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())

	if len(nft.scripts) != 0 || a.feed != nil {
		t.Fatalf("an answer without entries isn't a list: %v", nft.scripts)
	}
}

func TestNotModifiedKeepsTheList(t *testing.T) {
	nft := &fakeNft{}
	var sawTag string
	server, _ := feedServer(t, func(w http.ResponseWriter, r *http.Request) {
		if sawTag = r.Header.Get("If-None-Match"); sawTag == `"v1"` {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", `"v1"`)
		_ = json.NewEncoder(w).Encode(Feed{Mode: "observe", Entries: []FeedEntry{{Address: "203.0.113.9", ExpiresAt: time.Now().Add(time.Hour).Unix()}}})
	})
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())
	a.tick(context.Background())

	if sawTag != `"v1"` {
		t.Fatalf("the second poll should send the ETag, sent %q", sawTag)
	}
	if a.feed == nil || len(a.feed.Entries) != 1 {
		t.Fatalf("a 304 should keep the list: %+v", a.feed)
	}
}

func TestCountsAreDeltasAndSurviveAFailedReport(t *testing.T) {
	a := newAgent("http://unused", "token", "edge", "80, 443", &fakeNft{})

	a.count(map[netip.Prefix]element{prefix("203.0.113.9"): {packets: 10, bytes: 600}})
	a.seen = map[netip.Prefix]element{prefix("203.0.113.9"): {packets: 10, bytes: 600}}
	a.count(map[netip.Prefix]element{prefix("203.0.113.9"): {packets: 15, bytes: 900}})
	a.seen = map[netip.Prefix]element{prefix("203.0.113.9"): {packets: 15, bytes: 900}}

	// Replaced, so its counter restarted.
	a.count(map[netip.Prefix]element{prefix("203.0.113.9"): {packets: 2, bytes: 120}})

	owed := a.pending[prefix("203.0.113.9")]
	if owed.Packets != 17 || owed.Bytes != 1020 || owed.Address != "203.0.113.9" {
		t.Fatalf("owed = %+v", owed)
	}
}

func TestReportSendsCountsOnceDelivered(t *testing.T) {
	nft := &fakeNft{}
	server, reports := feedServer(t, func(w http.ResponseWriter, _ *http.Request) {
		_ = json.NewEncoder(w).Encode(Feed{Mode: "enforce", Entries: []FeedEntry{}})
	})
	a := newAgent(server.URL, "token", "edge-1", "80, 443", nft)
	a.tick(context.Background())
	a.pending[prefix("203.0.113.9")] = counter{Address: "203.0.113.9", Packets: 3, Bytes: 180}

	if err := a.report(context.Background()); err != nil {
		t.Fatal(err)
	}

	if len(*reports) != 1 {
		t.Fatalf("reports = %v", *reports)
	}
	body := (*reports)[0]
	if body["edge"] != "edge-1" || body["mode"] != "enforce" {
		t.Fatalf("body = %v", body)
	}
	if got := body["counters"].([]any); len(got) != 1 {
		t.Fatalf("counters = %v", got)
	}
	if len(a.pending) != 0 {
		t.Fatalf("delivered counts should be cleared: %v", a.pending)
	}
}

func TestApplyFailureIsReportedAndKeptTrying(t *testing.T) {
	nft := &fakeNft{fail: errors.New("Operation not permitted")}
	server, _ := feedServer(t, func(w http.ResponseWriter, _ *http.Request) {
		_ = json.NewEncoder(w).Encode(Feed{Mode: "enforce", Entries: []FeedEntry{}})
	})
	a := newAgent(server.URL, "token", "edge", "80, 443", nft)

	a.tick(context.Background())

	if !strings.Contains(a.lastError, "Operation not permitted") {
		t.Fatalf("lastError = %q", a.lastError)
	}
}
