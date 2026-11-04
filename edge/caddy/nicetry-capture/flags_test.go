package nicetrycapture

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

const ctfPattern = `\^FLAG\^.+?\$FLAG\$`

func TestFlagsAreFoundInTheBodyAndHeadersOnThePatternsHosts(t *testing.T) {
	watches := parseResponseWatch(json.RawMessage(`[
		{"id": 3, "hosts": ["CTF.example"], "pattern": "` + jsonEscape(ctfPattern) + `"},
		{"id": 4, "hosts": ["other.example"], "pattern": "secret"},
		{"id": 5, "hosts": ["ctf.example"], "pattern": "(?<=x)lookbehind"}
	]`))
	if len(watches) != 2 {
		t.Fatalf("expected the lookbehind to be skipped, got %d patterns", len(watches))
	}

	r := httptest.NewRequest(http.MethodGet, "https://ctf.example:443/page", nil)
	header := http.Header{"Set-Cookie": {"f=^FLAG^cookie$FLAG$"}}
	body := []byte(`<p>^FLAG^aaa$FLAG$ and ^FLAG^bbb$FLAG$, again ^FLAG^aaa$FLAG$, secret</p>`)

	got := findFlags(watches, r, header, body)
	want := []flagMatch{{3, "^FLAG^cookie$FLAG$"}, {3, "^FLAG^aaa$FLAG$"}, {3, "^FLAG^bbb$FLAG$"}}

	if len(got) != len(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("match %d: got %v, want %v", i, got[i], want[i])
		}
	}
}

func TestNoFlagsOffThePatternsHostsOrWithoutPatterns(t *testing.T) {
	watches := parseResponseWatch(json.RawMessage(`[{"id": 3, "hosts": ["ctf.example"], "pattern": "` + jsonEscape(ctfPattern) + `"}]`))
	body := []byte(`^FLAG^aaa$FLAG$`)

	if got := findFlags(watches, httptest.NewRequest(http.MethodGet, "https://elsewhere.example/", nil), http.Header{}, body); got != nil {
		t.Errorf("expected nothing on another host, got %v", got)
	}
	if got := findFlags(nil, httptest.NewRequest(http.MethodGet, "https://ctf.example/", nil), http.Header{}, body); got != nil {
		t.Errorf("expected nothing without patterns, got %v", got)
	}
	if parseResponseWatch(nil) != nil {
		t.Error("expected a missing list to be no patterns")
	}
}

func jsonEscape(s string) string {
	b, _ := json.Marshal(s)
	return string(b[1 : len(b)-1])
}
