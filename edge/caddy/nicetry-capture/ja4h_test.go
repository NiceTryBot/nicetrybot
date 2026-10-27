package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestJA4HDescribesTheHeaders(t *testing.T) {
	r := httptest.NewRequest(http.MethodGet, "https://sign.example/dashboard/login", nil)
	r.ProtoMajor, r.ProtoMinor = 2, 0
	r.Header.Set("User-Agent", "Mozilla/5.0")
	r.Header.Set("Accept-Language", "en-US,en;q=0.9")
	r.Header.Set("Sec-CH-UA", `"Chromium";v="142"`)
	r.Header.Set("Referer", "https://sign.example/")
	r.AddCookie(&http.Cookie{Name: "site_session", Value: "x"})
	r.AddCookie(&http.Cookie{Name: "XSRF-TOKEN", Value: "y"})

	got := JA4H(r)
	parts := strings.Split(got, "_")

	if len(parts) != 3 || parts[0] != "ge20cr03enus" {
		t.Fatalf("unexpected JA4H %q", got)
	}
	if parts[2] == "000000000000" {
		t.Error("cookie names should be hashed")
	}
}

func TestJA4HIgnoresHeaderOrderAndCookieValues(t *testing.T) {
	one := httptest.NewRequest(http.MethodPost, "https://sign.example/", nil)
	one.Header.Set("Accept", "*/*")
	one.Header.Set("User-Agent", "a")
	one.AddCookie(&http.Cookie{Name: "s", Value: "1"})

	two := httptest.NewRequest(http.MethodPost, "https://sign.example/", nil)
	two.Header.Set("User-Agent", "a")
	two.Header.Set("Accept", "*/*")
	two.AddCookie(&http.Cookie{Name: "s", Value: "2"})

	if JA4H(one) != JA4H(two) {
		t.Errorf("%s != %s", JA4H(one), JA4H(two))
	}
	if !strings.HasPrefix(JA4H(one), "po11cn030000_") {
		t.Errorf("a script without a language: %s", JA4H(one))
	}
}
