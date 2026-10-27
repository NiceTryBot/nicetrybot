package nicetrycapture

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func liveTailFor(hosts ...string) *LiveTail {
	set := map[string]struct{}{}
	for _, host := range hosts {
		set[host] = struct{}{}
	}
	h := &hostSet{}
	h.current.Store(&set)
	return &LiveTail{hosts: h}
}

func TestOnlyTailedHostsAreMarked(t *testing.T) {
	handler := liveTailFor("sign.example")

	for host, want := range map[string]bool{"Sign.example:443": true, "sign.example": true, "other.example": false} {
		if got := handler.hosts.has(hostOnly(host)); got != want {
			t.Errorf("%s: marked = %v, want %v", host, got, want)
		}
	}
	if liveTailFor().hosts.has("sign.example") {
		t.Error("nothing should be marked when nobody is tailing")
	}
}

func TestTailingNeverChangesTheResponse(t *testing.T) {
	extra := new(caddyhttp.ExtraLogFields)
	r := httptest.NewRequest(http.MethodGet, "https://sign.example/login", nil)
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.ExtraLogFieldsCtxKey, extra))

	for _, req := range []*http.Request{r, httptest.NewRequest(http.MethodGet, "https://sign.example/", nil)} {
		w := httptest.NewRecorder()
		if err := liveTailFor("sign.example").ServeHTTP(w, req, caddyhttp.HandlerFunc(inner)); err != nil {
			t.Fatal(err)
		}
		if w.Body.String() != "ok" || w.Header().Get("Server") != "nginx" {
			t.Errorf("response changed: %q %q", w.Body.String(), w.Header().Get("Server"))
		}
	}
}

func TestTailedHostsAreFetchedLowercase(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer t" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		_, _ = w.Write([]byte(`{"hosts":["Sign.example","b.example"]}`))
	}))
	defer app.Close()

	hosts, err := fetchTailedHosts(app.Client(), app.URL, "t")
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := hosts["sign.example"]; !ok || len(hosts) != 2 {
		t.Errorf("unexpected hosts: %v", hosts)
	}

	if _, err := fetchTailedHosts(app.Client(), app.URL, "wrong"); err == nil {
		t.Error("a rejected token should be an error, keeping the last list")
	}
}
