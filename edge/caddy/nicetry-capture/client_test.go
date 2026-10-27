package nicetrycapture

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func clientFor(t *testing.T) *ClientIP {
	t.Helper()
	c := &ClientIP{}
	if err := c.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return c
}

// requestFrom builds a request arriving from remote, with Caddy's vars table and extra log fields.
func requestFrom(remote, cfConnectingIP string) (*http.Request, map[string]any, *caddyhttp.ExtraLogFields) {
	r := httptest.NewRequest(http.MethodGet, "https://settlement.example/", nil)
	r.RemoteAddr = remote
	if cfConnectingIP != "" {
		r.Header.Set("CF-Connecting-IP", cfConnectingIP)
	}
	vars := map[string]any{}
	extra := new(caddyhttp.ExtraLogFields)
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	ctx = context.WithValue(ctx, caddyhttp.ExtraLogFieldsCtxKey, extra)
	return r.WithContext(ctx), vars, extra
}

func TestCloudflareVouchesForTheVisitor(t *testing.T) {
	r, vars, extra := requestFrom("104.23.160.11:19911", "203.0.113.7")
	if err := clientFor(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner)); err != nil {
		t.Fatal(err)
	}

	if vars["nicetry_client_ip"] != "203.0.113.7" {
		t.Errorf("nicetry_client_ip = %v", vars["nicetry_client_ip"])
	}
	if got := loggedFields(extra); got["visitor_ip"] != "203.0.113.7" || got["via"] != "cloudflare" {
		t.Errorf("log fields = %v", got)
	}
}

func TestCloudflareOverIPv6(t *testing.T) {
	r, vars, _ := requestFrom("[2606:4700:10::ac43:1]:443", "2001:db8::42")
	_ = clientFor(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_client_ip"] != "2001:db8::42" {
		t.Errorf("nicetry_client_ip = %v", vars["nicetry_client_ip"])
	}
}

func TestAnyoneElseCannotChooseTheirIPWithTheHeader(t *testing.T) {
	r, vars, extra := requestFrom("198.51.100.20:5555", "1.2.3.4")
	_ = clientFor(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_client_ip"] != "198.51.100.20" {
		t.Errorf("nicetry_client_ip = %v, want the connection's own address", vars["nicetry_client_ip"])
	}
	if got := loggedFields(extra); len(got) != 0 {
		t.Errorf("direct traffic should add no log fields, got %v", got)
	}
}

func TestCloudflareWithAGarbledHeaderFallsBackToTheConnection(t *testing.T) {
	r, vars, extra := requestFrom("172.64.1.1:443", "not-an-ip")
	_ = clientFor(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_client_ip"] != "172.64.1.1" {
		t.Errorf("nicetry_client_ip = %v", vars["nicetry_client_ip"])
	}
	if got := loggedFields(extra); len(got) != 0 {
		t.Errorf("unexpected log fields %v", got)
	}
}

func TestTrustCanReplaceTheCloudflareRanges(t *testing.T) {
	c := &ClientIP{Trust: []string{"10.0.0.0/8"}}
	if err := c.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}

	r, vars, _ := requestFrom("10.1.2.3:80", "203.0.113.9")
	_ = c.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))
	if vars["nicetry_client_ip"] != "203.0.113.9" {
		t.Errorf("nicetry_client_ip = %v", vars["nicetry_client_ip"])
	}

	r, vars, _ = requestFrom("104.23.160.11:443", "203.0.113.9")
	_ = c.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))
	if vars["nicetry_client_ip"] != "104.23.160.11" {
		t.Errorf("Cloudflare should no longer be trusted, got %v", vars["nicetry_client_ip"])
	}
}
