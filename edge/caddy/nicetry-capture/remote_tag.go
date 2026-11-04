package nicetrycapture

import (
	"bufio"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func init() {
	caddy.RegisterModule(RemoteTag{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_remote_tag", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		t := new(RemoteTag)
		return t, t.UnmarshalCaddyfile(h.Dispenser)
	})
}

// remoteTagHeader is the response header a site's app tags a visitor with (NiceTryBot's Remote
// Tagging); remoteTagCookie is where the edge keeps the tag until the visitor's next page load, when the
// gym check (which gets it among the gym's cookies) has Known Faces fingerprint them.
const (
	remoteTagHeader = "NiceTryBot-Tag"
	remoteTagCookie = "_q_tag"
)

// remoteTagShape is what a tag looks like: v1.<base64url payload>.<base64url HMAC-SHA256>. Only
// NiceTryBot can check the signature; the edge just refuses to put anything else in a cookie.
var remoteTagShape = regexp.MustCompile(`^v1\.[A-Za-z0-9_-]{8,1000}\.[A-Za-z0-9_-]{40,64}$`)

// RemoteTag is the nicetry_remote_tag handler. A site's response never shows its visitor the
// NiceTryBot-Tag header: the edge takes it off, and when it holds a well-formed tag, sets it as the
// _q_tag cookie instead (Secure, HttpOnly, SameSite=Lax, for MaxAge). Nothing else about the response
// changes, so the site's app tags people by adding one header, without redirecting anyone.
type RemoteTag struct {
	MaxAge caddy.Duration `json:"max_age,omitempty"`
}

// CaddyModule returns the Caddy module information.
func (RemoteTag) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_remote_tag",
		New: func() caddy.Module { return new(RemoteTag) },
	}
}

// Provision sets the cookie's lifetime: a day unless told otherwise.
func (t *RemoteTag) Provision(caddy.Context) error {
	if t.MaxAge <= 0 {
		t.MaxAge = caddy.Duration(24 * time.Hour)
	}
	return nil
}

func (t *RemoteTag) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	return next.ServeHTTP(&remoteTagWriter{ResponseWriter: w, maxAge: time.Duration(t.MaxAge)}, r)
}

// remoteTagWriter turns the tag header into the cookie just before the response's headers go out.
type remoteTagWriter struct {
	http.ResponseWriter
	maxAge  time.Duration
	written bool
}

func (rw *remoteTagWriter) swap() {
	if rw.written {
		return
	}
	rw.written = true
	header := rw.Header()
	tag := header.Get(remoteTagHeader)
	if tag == "" {
		return
	}
	header.Del(remoteTagHeader)
	if !remoteTagShape.MatchString(tag) {
		return
	}
	header.Add("Set-Cookie", remoteTagCookie+"="+tag+"; Path=/; Max-Age="+strconv.Itoa(int(rw.maxAge/time.Second))+"; HttpOnly; Secure; SameSite=Lax")
}

func (rw *remoteTagWriter) WriteHeader(code int) {
	// Informational answers (103 Early Hints) come before the real one, whose headers hold the tag.
	if code >= 200 {
		rw.swap()
	}
	rw.ResponseWriter.WriteHeader(code)
}

func (rw *remoteTagWriter) Write(p []byte) (int, error) {
	rw.swap()
	return rw.ResponseWriter.Write(p)
}

// Flush and Hijack reach the writer underneath, for streamed answers and websockets.
func (rw *remoteTagWriter) Flush() {
	rw.swap()
	_ = http.NewResponseController(rw.ResponseWriter).Flush()
}

func (rw *remoteTagWriter) Hijack() (net.Conn, *bufio.ReadWriter, error) {
	return http.NewResponseController(rw.ResponseWriter).Hijack()
}

func (rw *remoteTagWriter) Unwrap() http.ResponseWriter { return rw.ResponseWriter }

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_remote_tag {
//	    max_age <duration>
//	}
func (t *RemoteTag) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "max_age":
			var raw string
			if !d.Args(&raw) {
				return d.ArgErr()
			}
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("max_age: %v", err)
			}
			t.MaxAge = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_remote_tag option %q", d.Val())
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*RemoteTag)(nil)
	_ caddyhttp.MiddlewareHandler = (*RemoteTag)(nil)
	_ caddyfile.Unmarshaler       = (*RemoteTag)(nil)
)
