package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func verdictHandler(t *testing.T, raw string) *Verdict {
	t.Helper()
	verdicts, err := parseVerdicts([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	m := &verdictMap{}
	m.current.Store(&verdicts)
	return &Verdict{verdicts: m}
}

func serveVerdict(t *testing.T, v *Verdict, ip, sent string) string {
	t.Helper()
	r := httptest.NewRequest(http.MethodPost, "https://claims.example/livewire/update", nil)
	r.RemoteAddr = ip + ":443"
	if sent != "" {
		r.Header.Set(verdictHeader, sent)
	}
	var seen string
	if err := v.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) error {
		seen = r.Header.Get(verdictHeader)
		return nil
	})); err != nil {
		t.Fatal(err)
	}
	return seen
}

func TestAFlaggedClientsRequestsCarryItsVerdictToTheOrigin(t *testing.T) {
	v := verdictHandler(t, `{"verdicts":[{"ip":"203.0.113.7","header":"fraud; score=15.53; reasons=Window%20414%C3%97736"}]}`)

	if got := serveVerdict(t, v, "203.0.113.7", ""); got != "fraud; score=15.53; reasons=Window%20414%C3%97736" {
		t.Errorf("origin got %q", got)
	}
}

func TestAVisitorCannotForgeOrCarryAVerdict(t *testing.T) {
	v := verdictHandler(t, `{"verdicts":[{"ip":"203.0.113.7","header":"fraud; score=15.53"}]}`)

	if got := serveVerdict(t, v, "198.51.100.1", "clean; score=0"); got != "" {
		t.Errorf("an unflagged visitor's own header reached the origin: %q", got)
	}
	if got := serveVerdict(t, v, "203.0.113.7", "clean; score=0"); got != "fraud; score=15.53" {
		t.Errorf("a flagged visitor replaced its verdict: %q", got)
	}
}

func TestVerdictsMustBeAListOfSafeHeaderValues(t *testing.T) {
	if _, err := parseVerdicts([]byte(`{"something":"else"}`)); err == nil {
		t.Error("an answer without a verdicts list was taken")
	}
	verdicts, err := parseVerdicts([]byte(`{"verdicts":[{"ip":"203.0.113.1","header":"fraud\r\nX-Evil: 1"},{"ip":"203.0.113.2","header":"fraud; score=9"}]}`))
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := verdicts["203.0.113.1"]; ok || verdicts["203.0.113.2"] != "fraud; score=9" {
		t.Errorf("verdicts = %v", verdicts)
	}
}
