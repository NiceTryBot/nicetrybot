package nicetrycapture

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddytls"
)

func TestTheAskCarriesTheTokenInAHeaderAndNeverInAnError(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer edge-secret" || r.URL.Query().Has("token") {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		if r.URL.Query().Get("domain") == "shop.example" && r.URL.Query().Get("v") == "1" {
			w.WriteHeader(http.StatusOK)
			return
		}
		w.WriteHeader(http.StatusNotFound)
	}))
	defer app.Close()

	p := &AskPermission{Endpoint: app.URL + "/caddy/ask?v=1", Token: "edge-secret"}
	if err := p.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}

	if err := p.CertificateAllowed(context.Background(), "shop.example"); err != nil {
		t.Fatalf("a known hostname should be allowed: %v", err)
	}
	err := p.CertificateAllowed(context.Background(), "stranger.example")
	if !errors.Is(err, caddytls.ErrPermissionDenied) || strings.Contains(err.Error(), "edge-secret") {
		t.Fatalf("expected a denial without the token in it, got %v", err)
	}

	app.Close()
	err = p.CertificateAllowed(context.Background(), "shop.example")
	if err == nil || errors.Is(err, caddytls.ErrPermissionDenied) || strings.Contains(err.Error(), "edge-secret") {
		t.Fatalf("expected a failure to ask, without the token in it, got %v", err)
	}
}
