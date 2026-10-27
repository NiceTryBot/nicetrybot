package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func siteHeadersFor(servers map[string]string) *SiteHeaders {
	m := &serverMap{}
	m.current.Store(&servers)
	return &SiteHeaders{servers: m}
}

// inner stands in for the rest of the chain: the Caddyfile's default disguise, then the origin.
func inner(w http.ResponseWriter, r *http.Request) error {
	w.Header().Set("Server", "nginx")
	w.WriteHeader(http.StatusOK)
	_, err := w.Write([]byte("ok"))
	return err
}

func TestADomainsChosenServerHeaderWins(t *testing.T) {
	h := siteHeadersFor(map[string]string{"honeypot.example": "Apache/2.4.29 (Ubuntu)"})

	w := httptest.NewRecorder()
	_ = h.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "https://Honeypot.example:443/wp-login.php", nil), caddyhttp.HandlerFunc(inner))
	if got := w.Header().Get("Server"); got != "Apache/2.4.29 (Ubuntu)" {
		t.Errorf("Server = %q", got)
	}

	w = httptest.NewRecorder()
	_ = h.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "https://plain.example/", nil), caddyhttp.HandlerFunc(inner))
	if got := w.Header().Get("Server"); got != "nginx" {
		t.Errorf("an unconfigured domain should keep the default, got %q", got)
	}
}

func TestServerHeadersWithLineBreaksAreDropped(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"sites":[{"host":"a.example","server":"IIS"},{"host":"b.example","server":"x\r\nSet-Cookie: y"}]}`))
	}))
	defer app.Close()

	servers, err := fetchServers(app.Client(), app.URL, "t")
	if err != nil {
		t.Fatal(err)
	}
	if servers["a.example"] != "IIS" || len(servers) != 1 {
		t.Errorf("unexpected servers: %v", servers)
	}
}
