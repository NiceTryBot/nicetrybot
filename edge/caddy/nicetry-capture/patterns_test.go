package nicetrycapture

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// tooLarge is a glob (or LIKE pattern) longer than any real one, which the edge refuses to compile.
var tooLarge = strings.Repeat("*", maxPatternBytes)

// A pattern from the app too large to compile is skipped. Before, it panicked in the poller, which took
// Caddy down on every edge.
func TestATriggerTooLargeToCompileIsSkipped(t *testing.T) {
	t.Parallel()
	feed := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{"triggers": []map[string]any{
			{"id": 1, "hosts": []string{"sign.example"}, "path": tooLarge},
			{"id": 2, "hosts": []string{"sign.example"}, "path": "/login*"},
		}})
	}))
	defer feed.Close()

	targets, _, err := fetchTargets(feed.Client(), feed.URL, "t", "triggers", true, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(targets) != 1 || targets[0].ID != 2 {
		t.Fatalf("expected only the trigger that compiles, got %d", len(targets))
	}
}

func TestARulePatternTooLargeToCompileIsFalse(t *testing.T) {
	t.Parallel()
	e := &ruleExpr{Kind: "like", Field: "path", Pattern: strings.ReplaceAll(tooLarge, "*", "%")}
	e.compile()
	if got := e.eval(&requestFacts{path: "/login"}); got == nil || *got {
		t.Fatalf("expected false, got %v", got)
	}
}

func TestACategoryTooLargeToCompileIsSkipped(t *testing.T) {
	t.Parallel()
	raw, _ := json.Marshal([]map[string]any{{"id": 1, "patterns": []string{tooLarge}}, {"id": 2, "patterns": []string{"/wp-*"}}})
	env := ruleEnvFrom(map[string]json.RawMessage{"categories": raw})
	if len(env.categories) != 1 || env.categories[0].ID != 2 {
		t.Fatalf("expected only the category that compiles, got %v", env.categories)
	}
}
