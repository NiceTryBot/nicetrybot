package nicetrycapture

import (
	"bytes"
	"errors"
	"io"
	"net/http"
	"strings"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(PHPGuard{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_php_guard", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		g := new(PHPGuard)
		return g, g.UnmarshalCaddyfile(h.Dispenser)
	})
}

// uploadProgressField is the form field that makes PHP write upload progress into a session
// (session.upload_progress.name's default). PHP acts on it while it parses the body, before any of the
// site's own code runs, which is where CVE-2026-109618 and CVE-2026-109619 live.
const uploadProgressField = "PHP_SESSION_UPLOAD_PROGRESS"

// guardPrefix is how much of a multipart body is read before it goes to the origin, so a small attack
// is turned away with a 403. Anything longer is still checked as it streams.
const guardPrefix = 64 << 10

var (
	errUploadProgress = errors.New("nicetry_php_guard: request body names a PHP upload progress field")
	errMultipart      = errors.New("nicetry_php_guard: multipart uploads are refused")
)

// PHPGuard is the nicetry_php_guard handler: it keeps multipart bodies that name PHP's session upload
// progress field away from origins. PHP compares the field name byte for byte, so any body containing
// it is refused. Short bodies get a 403 before the origin hears anything; in a long one the field is
// held back as it streams and the upload is cut off before the origin sees it.
//
// BlockMultipart is the stricter switch: it refuses every multipart body, so the parser PHP runs on
// uploads never sees one.
type PHPGuard struct {
	// Fields replaces the field names to refuse (a site that changed session.upload_progress.name).
	Fields []string `json:"fields,omitempty"`

	// BlockMultipart refuses every multipart/form-data request with a 403.
	BlockMultipart bool `json:"block_multipart,omitempty"`

	needles [][]byte
}

// CaddyModule returns the Caddy module information.
func (PHPGuard) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_php_guard",
		New: func() caddy.Module { return new(PHPGuard) },
	}
}

// Provision sets up the field names to look for.
func (g *PHPGuard) Provision(caddy.Context) error {
	fields := g.Fields
	if len(fields) == 0 {
		fields = []string{uploadProgressField}
	}
	g.needles = make([][]byte, 0, len(fields))
	for _, field := range fields {
		if field != "" {
			g.needles = append(g.needles, []byte(field))
		}
	}
	return nil
}

func (g *PHPGuard) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	if g.BlockMultipart && isMultipart(r) {
		blocked(r, "php_multipart")
		return caddyhttp.Error(http.StatusForbidden, errMultipart)
	}

	if len(g.needles) == 0 || r.Body == nil || r.Body == http.NoBody || !isMultipart(r) {
		return next.ServeHTTP(w, r)
	}

	guarded := newGuardedBody(r.Body, g.needles)
	prefix, err := io.ReadAll(io.LimitReader(guarded, guardPrefix))
	if errors.Is(err, errUploadProgress) {
		blockedUploadProgress(r, false)
		return caddyhttp.Error(http.StatusForbidden, err)
	}
	guarded.onBlock = func() { blockedUploadProgress(r, true) }
	r.Body = replayBody{Reader: io.MultiReader(bytes.NewReader(prefix), guarded), Closer: r.Body}
	return next.ServeHTTP(w, r)
}

// isMultipart reports whether PHP would parse the body as multipart/form-data. PHP lowercases the
// Content-Type and cuts it at the first ";", "," or space; every header copy is checked, since an
// origin's server might use a different one than Go does.
func isMultipart(r *http.Request) bool {
	for _, value := range r.Header.Values("Content-Type") {
		if strings.Contains(strings.ToLower(value), "multipart/form-data") {
			return true
		}
	}
	return false
}

// blocked records a refusal in the access log under the given bot_tag.
func blocked(r *http.Request, botTag string) {
	caddyhttp.SetVar(r.Context(), "bot_tag", botTag)
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("action", "blocked"))
	}
}

// blockedUploadProgress records the refusal in the access log, and logs a warning when the field
// turned up too deep in the body for a clean 403.
func blockedUploadProgress(r *http.Request, midStream bool) {
	blocked(r, "php_upload_progress")
	if midStream {
		caddy.Log().Named("nicetry_php_guard").Warn("cut off an upload naming PHP's upload progress field",
			zap.String("host", r.Host), zap.String("uri", r.RequestURI))
	}
}

// guardedBody passes a body through while looking for any of the needles. The last few bytes read are
// held back until more arrive (or the body ends), so a needle split across reads is never released.
type guardedBody struct {
	src      io.Reader
	needles  [][]byte
	holdback int
	onBlock  func() // called when a needle turns up after the prefix was read

	chunk   []byte
	pending []byte
	ready   []byte
	err     error
}

func newGuardedBody(src io.Reader, needles [][]byte) *guardedBody {
	longest := 0
	for _, needle := range needles {
		longest = max(longest, len(needle))
	}
	return &guardedBody{src: src, needles: needles, holdback: longest - 1}
}

func (b *guardedBody) Read(p []byte) (int, error) {
	for len(b.ready) == 0 {
		if b.err != nil {
			return 0, b.err
		}

		if b.chunk == nil {
			b.chunk = make([]byte, 32<<10)
		}
		n, err := b.src.Read(b.chunk)
		b.pending = append(b.pending, b.chunk[:n]...)

		for _, needle := range b.needles {
			if bytes.Contains(b.pending, needle) {
				b.pending, b.err = nil, errUploadProgress
				if b.onBlock != nil {
					b.onBlock()
				}
				return 0, b.err
			}
		}

		switch {
		case err != nil:
			b.ready, b.pending, b.err = b.pending, nil, err
		case len(b.pending) > b.holdback:
			cut := len(b.pending) - b.holdback
			b.ready = b.pending[:cut:cut]
			b.pending = append([]byte(nil), b.pending[cut:]...)
		}
	}

	n := copy(p, b.ready)
	b.ready = b.ready[n:]
	return n, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_php_guard [{
//	    fields <name...>
//	    block_multipart
//	}]
func (g *PHPGuard) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "fields":
			g.Fields = append(g.Fields, d.RemainingArgs()...)
			if len(g.Fields) == 0 {
				return d.ArgErr()
			}
		case "block_multipart":
			if d.NextArg() {
				return d.ArgErr()
			}
			g.BlockMultipart = true
		default:
			return d.Errf("unknown nicetry_php_guard option %q", d.Val())
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*PHPGuard)(nil)
	_ caddyhttp.MiddlewareHandler = (*PHPGuard)(nil)
	_ caddyfile.Unmarshaler       = (*PHPGuard)(nil)
)
