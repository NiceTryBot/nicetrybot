package nicetrycapture

import (
	"context"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// listWith builds an allow list holding the given ranges, without polling.
func listWith(cidrs ...string) *allowList {
	l := &allowList{}
	prefixes := make([]netip.Prefix, 0, len(cidrs))
	for _, c := range cidrs {
		if prefix, err := parseAllowEntry(c); err == nil {
			prefixes = append(prefixes, prefix)
		}
	}
	l.current.Store(&prefixes)
	return l
}

// allowRequest builds a request whose client IP is already worked out (as nicetry_client would).
func allowRequest(ip string) (*http.Request, map[string]any) {
	r := httptest.NewRequest(http.MethodGet, "https://shop.example/", nil)
	vars := map[string]any{"nicetry_client_ip": ip}
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	return r.WithContext(ctx), vars
}

func TestParseAllowKeepsRangesAndSkipsJunk(t *testing.T) {
	ranges, err := parseAllow([]byte(`{"allow":["66.249.64.0/27","203.0.113.5","not-an-ip","2001:4860:4801:10::/64"]}`))
	if err != nil {
		t.Fatal(err)
	}
	if len(ranges) != 3 {
		t.Fatalf("kept %d ranges, want 3", len(ranges))
	}
}

func TestParseAllowNeedsAList(t *testing.T) {
	if _, err := parseAllow([]byte(`{}`)); err == nil {
		t.Error("an answer with no allow list should be an error, so the last list is kept")
	}
	if ranges, err := parseAllow([]byte(`{"allow":[]}`)); err != nil || len(ranges) != 0 {
		t.Errorf("an empty list means nothing is allowed, got %v, %v", ranges, err)
	}
}

func TestAllowListHas(t *testing.T) {
	l := listWith("66.249.64.0/27", "2001:4860:4801:10::/64")

	for ip, want := range map[string]bool{
		"66.249.64.10":            true,
		"66.249.64.200":           false,
		"8.8.8.8":                 false,
		"2001:4860:4801:10::abcd": true,
		"2001:4860:4801:11::1":    false,
		"not-an-ip":               false,
	} {
		if got := l.has(ip); got != want {
			t.Errorf("has(%q) = %v, want %v", ip, got, want)
		}
	}
}

func TestServeHTTPMarksAnAllowedClient(t *testing.T) {
	a := &Allow{ranges: listWith("66.249.64.0/27")}

	r, vars := allowRequest("66.249.64.10")
	if err := a.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner)); err != nil {
		t.Fatal(err)
	}
	if vars[nicetryAllowedVar] != true || !isAllowed(r) {
		t.Errorf("an allowed client should be marked, vars = %v", vars)
	}
}

func TestServeHTTPLeavesOthersUnmarked(t *testing.T) {
	a := &Allow{ranges: listWith("66.249.64.0/27")}

	r, vars := allowRequest("8.8.8.8")
	_ = a.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))
	if _, marked := vars[nicetryAllowedVar]; marked || isAllowed(r) {
		t.Errorf("a client that isn't on the list should not be marked, vars = %v", vars)
	}
}
