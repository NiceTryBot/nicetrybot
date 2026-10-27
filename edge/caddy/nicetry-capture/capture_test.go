package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestLivewireV2FailedLoginKeepsTheErrorAndMasksThePassword(t *testing.T) {
	body := `{"effects":{"html":"<div>` + strings.Repeat("x", 2000) + `</div>","dirty":["password"]},` +
		`"serverMemo":{"data":{"email":"jane.doe@example.com","password":"hunter2","remember":false},` +
		`"errors":{"email":["These credentials do not match our records."]},"checksum":"abc123"}}`

	got := Redact([]byte(body), "application/json", 4096)

	for _, want := range []string{"These credentials do not match our records.", `"password":"[redacted]"`, `"checksum":"[redacted]"`, "j***@example.com", "…[2011 chars]"} {
		if !strings.Contains(got, want) {
			t.Errorf("expected %q in %s", want, got)
		}
	}
	for _, leaked := range []string{"hunter2", "jane.doe", "abc123"} {
		if strings.Contains(got, leaked) {
			t.Errorf("leaked %q in %s", leaked, got)
		}
	}
}

func TestHtmlKeepsVisibleTextAndMasksTokens(t *testing.T) {
	body := `<html><head><meta name="csrf-token" content="s3cr3t"><script>var token="zzz"</script></head>` +
		`<body><p class="error">Invalid password for bob@example.com</p></body></html>`

	got := Redact([]byte(body), "text/html; charset=utf-8", 4096)

	if got != "Invalid password for b***@example.com" {
		t.Errorf("unexpected text: %q", got)
	}
}

func TestFormEncodedPairsAreMasked(t *testing.T) {
	got := Redact([]byte("user=bob&password=hunter2&api_key=k1"), "text/plain", 4096)

	if strings.Contains(got, "hunter2") || strings.Contains(got, "k1") {
		t.Errorf("leaked a secret: %q", got)
	}
}

func TestExcerptIsCutToMaxBytesOnARuneBoundary(t *testing.T) {
	got := Redact([]byte(strings.Repeat("é", 100)), "text/plain", 11)

	if got != strings.Repeat("é", 5)+"…" {
		t.Errorf("unexpected cut: %q", got)
	}
}

func TestTargetsMatchHostMethodAndGlob(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 7, Hosts: map[string]bool{"sign.example": true}, Method: "POST", Path: GlobRegexp("/livewire/message/*login")}})

	cases := []struct {
		method, url string
		want        bool
	}{
		{"POST", "https://sign.example/livewire/message/filament.core.auth.login", true},
		{"POST", "https://SIGN.example:443/livewire/message/filament.core.auth.login?x=1", true},
		{"GET", "https://sign.example/livewire/message/filament.core.auth.login", false},
		{"POST", "https://other.example/livewire/message/filament.core.auth.login", false},
		{"POST", "https://sign.example/livewire/message/filament.core.auth.logout", false},
	}

	for _, c := range cases {
		r := httptest.NewRequest(c.method, c.url, nil)
		if got := ts.match(r) != nil; got != c.want {
			t.Errorf("%s %s: matched=%v, want %v", c.method, c.url, got, c.want)
		}
	}
}

func TestAnUnchangedListIsNotFetchedAgain(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-None-Match") == `"v1"` {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", `"v1"`)
		_, _ = w.Write([]byte(`{"captures":[{"id":3,"hosts":["sign.example"],"method":"POST","path":"/login","request":true,"watch":[{"id":1,"kind":"identity","value":"GLOBEX"}]}]}`))
	}))
	defer app.Close()

	targets, etag, err := fetchTargets(app.Client(), app.URL, "t", "captures", false, "")
	if err != nil || etag != `"v1"` || len(targets) != 1 || !targets[0].Request || targets[0].Watch[0].Value != "globex" {
		t.Fatalf("first fetch: %+v %q %v", targets, etag, err)
	}

	targets, etag, err = fetchTargets(app.Client(), app.URL, "t", "captures", false, etag)
	if err != nil || targets != nil || etag != `"v1"` {
		t.Errorf("an unchanged list should keep the last one: %+v %q %v", targets, etag, err)
	}
}
