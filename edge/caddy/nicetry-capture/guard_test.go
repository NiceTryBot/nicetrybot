package nicetrycapture

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func guardFor(t *testing.T, token string) *Guard {
	t.Helper()
	g := &Guard{Token: token}
	if err := g.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return g
}

func guarded(g *Guard, r *http.Request) (*countingOrigin, error) {
	o := &countingOrigin{}
	return o, g.ServeHTTP(httptest.NewRecorder(), r, o.handler())
}

func statusOf(err error) int {
	var he caddyhttp.HandlerError
	if errors.As(err, &he) {
		return he.StatusCode
	}
	return 0
}

func TestTheHopValueIsAHashOfTheTokenSharedByTheInstallation(t *testing.T) {
	a, b, other := hopValue("edge-token"), hopValue("edge-token"), hopValue("another-installation")
	if len(a) != 16 || a != b || a == other {
		t.Fatalf("hop values: %q %q %q", a, b, other)
	}
	if a == "edge-token"[:10] {
		t.Fatal("the hop value shouldn't show the token")
	}
}

func TestARequestTheEdgeSentItselfIsALoop(t *testing.T) {
	g := guardFor(t, "edge-token")

	r, vars, extra := lockdownRequest(http.MethodGet, "https://shop.example/")
	r.Header.Set(hopHeader, hopValue("edge-token"))
	o, err := guarded(g, r)
	if statusOf(err) != http.StatusLoopDetected || o.count() != 0 {
		t.Fatalf("expected a 508 before anything else, got %v (reached %d)", err, o.count())
	}
	if vars["bot_tag"] != "loop" || loggedFields(extra)["action"] != "blocked" {
		t.Errorf("expected it logged as a blocked loop: %v %v", vars["bot_tag"], loggedFields(extra))
	}
}

func TestAVisitorsOwnHopHeaderGoesNoFurther(t *testing.T) {
	g := guardFor(t, "edge-token")

	// Another installation's edge, or a visitor making one up: let through, without the header.
	r, vars, _ := lockdownRequest(http.MethodGet, "https://shop.example/")
	r.Header.Set(hopHeader, hopValue("another-installation"))
	o, err := guarded(g, r)
	if err != nil || o.count() != 1 {
		t.Fatalf("expected the request through, got %v", err)
	}
	if r.Header.Get(hopHeader) != "" {
		t.Error("the visitor's X-NiceTry-Hop should be dropped")
	}
	if vars[hopVar] != hopValue("edge-token") {
		t.Errorf("origins should get the installation's own hop value, got %v", vars[hopVar])
	}
}

func TestAPathWithDotSegmentsIsA400(t *testing.T) {
	g := guardFor(t, "edge-token")

	for _, target := range []string{"https://shop.example/_q/../login", "https://shop.example/a/./b", "https://shop.example/%2e%2e/etc/passwd"} {
		r, vars, _ := lockdownRequest(http.MethodGet, target)
		o, err := guarded(g, r)
		if statusOf(err) != http.StatusBadRequest || o.count() != 0 || vars["bot_tag"] != "dot_segments" {
			t.Errorf("%s: expected a 400, got %v (reached %d)", target, err, o.count())
		}
	}

	r, _, _ := lockdownRequest(http.MethodGet, "https://shop.example//login/.well-known")
	if o, err := guarded(g, r); err != nil || o.count() != 1 {
		t.Errorf("empty segments and dot-files are fine, got %v", err)
	}
}
