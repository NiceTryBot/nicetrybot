package nicetrycapture

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"sort"
	"strings"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(HeaderPrint{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_ja4h", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		h.Next()
		return new(HeaderPrint), nil
	})
}

// HeaderPrint is the nicetry_ja4h handler: it adds what a request's headers say about the client to
// the access log, next to the TLS fingerprints. That's a JA4H-style fingerprint plus the raw client
// hints (Sec-CH-UA, Sec-CH-UA-Platform) and Accept-Language, which is where automated Chrome tends to
// give itself away even when its TLS fingerprint is a real browser's.
type HeaderPrint struct{}

// CaddyModule returns the Caddy module information.
func (HeaderPrint) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_ja4h",
		New: func() caddy.Module { return new(HeaderPrint) },
	}
}

func (HeaderPrint) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("ja4h", JA4H(r)))
		for field, header := range map[string]string{"ch_ua": "Sec-CH-UA", "ch_platform": "Sec-CH-UA-Platform", "lang": "Accept-Language"} {
			if value := r.Header.Get(header); value != "" {
				extra.Add(zap.String(field, truncate(value, 300)))
			}
		}
	}
	return next.ServeHTTP(w, r)
}

// JA4H computes a JA4H-style header fingerprint: a_b_c, where
//
//	a = method (2 letters) + HTTP version + cookie (c/n) + referer (r/n) + header count + language (4)
//	b = first 12 hex of SHA-256 over the header names (lowercase, sorted, without cookie and referer)
//	c = first 12 hex of SHA-256 over the cookie names (sorted), or zeros without cookies
//
// It differs from JA4H in two ways: Go keeps headers in a map, so their order is lost and b hashes
// the names sorted; and the fourth part (cookie names with values) is left out, since session values
// make every visitor unique without telling one client from another.
func JA4H(r *http.Request) string {
	method := strings.ToLower(r.Method)
	if len(method) > 2 {
		method = method[:2]
	}

	var names []string
	for name := range r.Header {
		lower := strings.ToLower(name)
		if lower != "cookie" && lower != "referer" {
			names = append(names, lower)
		}
	}
	// HTTP/1 sends Host as a header (Go moves it to r.Host); HTTP/2 and 3 send :authority instead.
	if r.ProtoMajor == 1 && r.Host != "" {
		names = append(names, "host")
	}
	sort.Strings(names)

	cookie, referer := "n", "n"
	if r.Header.Get("Cookie") != "" {
		cookie = "c"
	}
	if r.Header.Get("Referer") != "" {
		referer = "r"
	}

	lang := strings.NewReplacer("-", "", ",", "", ";", "", " ", "").Replace(strings.ToLower(r.Header.Get("Accept-Language")))
	lang = (lang + "0000")[:4]

	var cookieNames []string
	for _, c := range r.Cookies() {
		cookieNames = append(cookieNames, c.Name)
	}
	sort.Strings(cookieNames)

	a := fmt.Sprintf("%s%d%d%s%s%02d%s", method, r.ProtoMajor, r.ProtoMinor, cookie, referer, min(len(names), 99), lang)
	return a + "_" + hash12(strings.Join(names, ",")) + "_" + hash12(strings.Join(cookieNames, ","))
}

func hash12(s string) string {
	if s == "" {
		return "000000000000"
	}
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])[:12]
}

var _ caddyhttp.MiddlewareHandler = (*HeaderPrint)(nil)
