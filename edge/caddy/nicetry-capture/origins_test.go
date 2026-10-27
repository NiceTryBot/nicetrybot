package nicetrycapture

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func originsFor(routes map[string]originRoute) *Origins {
	m := &originMap{}
	m.current.Store(&routes)
	return &Origins{origins: m}
}

// withVars gives a request the vars table Caddy's server would.
func withVars(r *http.Request) (*http.Request, map[string]any) {
	vars := map[string]any{}
	return r.WithContext(context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)), vars
}

func TestAKnownDomainGetsItsOriginAsVars(t *testing.T) {
	h := originsFor(map[string]originRoute{"preview.example": {
		Scheme: "https", Upstream: "sites.example.org:443", ServerName: "sites.example.org",
		HostHeader: "sites.example.org", ForwardedHostHeader: "X-Domain", Secret: "s3cret",
	}})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://Preview.example:443/", nil))
	if err := h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner)); err != nil {
		t.Fatal(err)
	}

	want := map[string]string{
		"nicetry_origin_scheme":   "https",
		"nicetry_origin_upstream": "sites.example.org:443",
		"nicetry_origin_sni":      "sites.example.org",
		"nicetry_origin_host":     "sites.example.org",
		"nicetry_origin_fwd":      "X-Domain",
		"nicetry_origin_site":     "Preview.example",
		"nicetry_origin_secret":   "s3cret",
	}
	for key, value := range want {
		if vars[key] != value {
			t.Errorf("%s = %v, want %q", key, vars[key], value)
		}
	}
}

func TestWithoutAChosenHeaderTheVisitorsHostRidesInXForwardedHost(t *testing.T) {
	h := originsFor(map[string]originRoute{"a.example": {Scheme: "http", Upstream: "o.example:80", HostHeader: "a.example"}})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://a.example/", nil))
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_origin_fwd"] != "X-Forwarded-Host" {
		t.Errorf("nicetry_origin_fwd = %v", vars["nicetry_origin_fwd"])
	}
}

func TestAnUnknownDomainGetsNoOrigin(t *testing.T) {
	h := originsFor(map[string]originRoute{})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://stranger.example/", nil))
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if len(vars) != 0 {
		t.Errorf("expected no vars, got %v", vars)
	}
}

func TestASlowOriginIsCutOffWithA504(t *testing.T) {
	h := originsFor(map[string]originRoute{"slow.example": {Scheme: "https", Upstream: "o.example:443", HostHeader: "slow.example", Timeout: 1}})

	// Stands in for reverse_proxy: waits on the origin until the request is canceled, then records a 499
	// (client closed request) the way Caddy 2.11 does.
	stuck := caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		select {
		case <-r.Context().Done():
			w.WriteHeader(499)
		case <-time.After(5 * time.Second):
			w.WriteHeader(http.StatusOK)
		}
		return nil
	})

	r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://slow.example/", nil))
	w := httptest.NewRecorder()
	err := h.ServeHTTP(w, r, stuck)

	var handlerErr caddyhttp.HandlerError
	if !errors.As(err, &handlerErr) || handlerErr.StatusCode != http.StatusGatewayTimeout {
		t.Fatalf("expected a 504 error for the error page, got %v", err)
	}
	if w.Code == 499 {
		t.Error("the 499 should have been held back")
	}
}

func TestAVisitorWhoHangsUpIsStillA499(t *testing.T) {
	h := originsFor(map[string]originRoute{"a.example": {Scheme: "https", Upstream: "o.example:443", HostHeader: "a.example", Timeout: 30}})

	r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://a.example/", nil))
	w := httptest.NewRecorder()
	_ = h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.WriteHeader(499)
		return nil
	}))
	if w.Code != 499 {
		t.Fatalf("expected a 499, got %d", w.Code)
	}
}

func TestAResponseThatStartedInTimeIsNotCutOff(t *testing.T) {
	h := originsFor(map[string]originRoute{"big.example": {Scheme: "https", Upstream: "o.example:443", HostHeader: "big.example", Timeout: 1}})

	// A long download: headers arrive at once, the body keeps flowing past the timeout.
	download := caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.WriteHeader(http.StatusOK)
		select {
		case <-r.Context().Done():
			return r.Context().Err()
		case <-time.After(1500 * time.Millisecond):
			return nil
		}
	})

	r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://big.example/", nil))
	if err := h.ServeHTTP(httptest.NewRecorder(), r, download); err != nil {
		t.Fatalf("the download was cut off: %v", err)
	}
}

func TestOriginsAreValidatedAndAnUnchangedListIsKept(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer t" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		if r.Header.Get("If-None-Match") == `"v1"` {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", `"v1"`)
		_, _ = w.Write([]byte(`{"origins":[
			{"host":"Good.example","scheme":"https","upstream":"o.example:443","server_name":"o.example","host_header":"good.example","forwarded_host_header":"X-Domain","secret":"s","timeout":30},
			{"host":"noport.example","scheme":"https","upstream":"o.example","host_header":"noport.example"},
			{"host":"ftp.example","scheme":"ftp","upstream":"o.example:21","host_header":"ftp.example"},
			{"host":"smuggle.example","scheme":"https","upstream":"o.example:443","host_header":"x\r\nEvil: 1"},
			{"host":"badname.example","scheme":"https","upstream":"o.example:443","host_header":"badname.example","forwarded_host_header":"X Domain"},
			{"host":"placeholder.example","scheme":"https","upstream":"o.example:443","host_header":"{env.SECRET}"},
			{"host":"site-smuggle.example","scheme":"https","upstream":"o.example:443","host_header":"site-smuggle.example","site_host":"x\r\nEvil: 1"}
		]}`))
	}))
	defer app.Close()

	origins, etag, err := fetchOrigins(app.Client(), app.URL, "t", "")
	if err != nil {
		t.Fatal(err)
	}
	if len(origins) != 1 || origins["good.example"].ForwardedHostHeader != "X-Domain" || origins["good.example"].Timeout != 30 {
		t.Errorf("unexpected origins: %+v", origins)
	}
	if etag != `"v1"` {
		t.Errorf("etag = %q", etag)
	}

	origins, _, err = fetchOrigins(app.Client(), app.URL, "t", etag)
	if err != nil || origins != nil {
		t.Errorf("an unchanged list should come back nil, got %v, %v", origins, err)
	}
}

func TestADomainsHeaderAndCachingChoicesBecomeVars(t *testing.T) {
	h := originsFor(map[string]originRoute{"case.example": {
		Scheme: "https", Upstream: "sites.example.org:443", HostHeader: "sites.example.org",
		HSTS: "max-age=31536000; includeSubDomains; preload", CacheAssets: true,
	}})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://case.example/", nil))
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_origin_hsts"] != "max-age=31536000; includeSubDomains; preload" {
		t.Errorf("nicetry_origin_hsts = %v", vars["nicetry_origin_hsts"])
	}
	if vars["nicetry_origin_cache_assets"] != "1" {
		t.Errorf("nicetry_origin_cache_assets = %v", vars["nicetry_origin_cache_assets"])
	}
}

func TestWithoutThoseChoicesTheVarsAreEmpty(t *testing.T) {
	h := originsFor(map[string]originRoute{"plain.example": {Scheme: "https", Upstream: "o.example:443", HostHeader: "plain.example"}})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://plain.example/", nil))
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner))

	if vars["nicetry_origin_hsts"] != "" || vars["nicetry_origin_cache_assets"] != "" {
		t.Errorf("expected empty vars, got hsts=%v cache=%v", vars["nicetry_origin_hsts"], vars["nicetry_origin_cache_assets"])
	}
}

func TestExtraHostHeadersCarryTheVisitorsHostAndOverwriteWhatTheyClaimed(t *testing.T) {
	h := originsFor(map[string]originRoute{"case.example": {
		Scheme: "https", Upstream: "o.example:443", HostHeader: "sites.example.org",
		ForwardedHostHeader: "X-Domain", ExtraForwardedHostHeaders: []string{"User-Custom-Domain", " "},
	}})

	r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://case.example:443/", nil))
	r.Header.Set("User-Custom-Domain", "victim.example")

	var seen string
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		seen = r.Header.Get("User-Custom-Domain")
		return nil
	}))

	if seen != "case.example" {
		t.Errorf("User-Custom-Domain = %q, want case.example", seen)
	}
}

func TestOnAPreviewTheOriginHearsTheSiteInEveryHostHeader(t *testing.T) {
	h := originsFor(map[string]originRoute{"case-example.preview.example": {
		Scheme: "https", Upstream: "o.example:443", HostHeader: "sites.example.org", SiteHost: "case.example",
		ForwardedHostHeader: "X-Domain", ExtraForwardedHostHeaders: []string{"User-Custom-Domain"},
	}})

	r, vars := withVars(httptest.NewRequest(http.MethodGet, "https://case-example.preview.example/", nil))

	var seen string
	_ = h.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		seen = r.Header.Get("User-Custom-Domain")
		return nil
	}))

	if vars["nicetry_origin_site"] != "case.example" || seen != "case.example" {
		t.Errorf("nicetry_origin_site = %v, User-Custom-Domain = %q, want case.example for both", vars["nicetry_origin_site"], seen)
	}
}

func TestARedirectToTheSiteKeepsTheVisitorOnThePreview(t *testing.T) {
	h := originsFor(map[string]originRoute{"case-example.preview.example": {
		Scheme: "https", Upstream: "o.example:443", HostHeader: "case.example", SiteHost: "case.example", Timeout: 5,
	}})

	for location, want := range map[string]string{
		"https://Case.example/login?next=%2Fhome#top": "https://case-example.preview.example/login?next=%2Fhome#top",
		"https://elsewhere.example/":                  "https://elsewhere.example/",
		"/login":                                      "/login",
	} {
		r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://case-example.preview.example/", nil))
		rec := httptest.NewRecorder()
		_ = h.ServeHTTP(rec, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
			w.Header().Set("Location", location)
			w.WriteHeader(http.StatusFound)
			return nil
		}))

		if got := rec.Header().Get("Location"); got != want {
			t.Errorf("Location %q became %q, want %q", location, got, want)
		}
	}
}

func TestOffAPreviewRedirectsAreLeftAlone(t *testing.T) {
	h := originsFor(map[string]originRoute{"www.case.example": {
		Scheme: "https", Upstream: "o.example:443", HostHeader: "www.case.example", SiteHost: "www.case.example",
	}})

	r, _ := withVars(httptest.NewRequest(http.MethodGet, "https://www.case.example/", nil))
	rec := httptest.NewRecorder()
	_ = h.ServeHTTP(rec, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.Header().Set("Location", "https://case.example/")
		w.WriteHeader(http.StatusMovedPermanently)
		return nil
	}))

	if got := rec.Header().Get("Location"); got != "https://case.example/" {
		t.Errorf("Location = %q, want it untouched", got)
	}
}
