package nicetrycapture

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Guard{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_guard", parseGuardCaddyfile)
}

// hopHeader marks every request the edge sends to an origin. Seeing this installation's own value come
// back means an origin points at the edge (a site's own preview hostname, or another site it serves).
const hopHeader = "X-NiceTry-Hop"

// hopVar holds the installation's hop value for the Caddyfile's origin blocks: header_up X-NiceTry-Hop
// {http.vars.nicetry_hop}.
const hopVar = "nicetry_hop"

// Guard is the nicetry_guard handler. Before any other NiceTry handler (or origin) hears of it, it
// refuses what no browser sends:
//
//   - a request this installation's edge sent itself, with nginx's 508 Loop Detected. Without it a site
//     whose origin points back at the edge would go round until its timeout, each turn a new request.
//   - a path with "." or ".." segments, with nginx's 400. Browsers resolve those before sending, and the
//     edge's matching and the origin could otherwise read such a path differently (/_q/../login).
//
// The hop value comes from the edge token, so every node of one installation shares it (a loop through
// several of them is caught) while a site chained through another installation's edge isn't refused.
// It's a hash, so origins that see it learn nothing about the token. A visitor's own X-NiceTry-Hop is
// dropped: a made-up value does nothing, and the real one only gets that visitor a 508.
type Guard struct {
	Token string `json:"token,omitempty"`

	hop string
}

// CaddyModule returns the Caddy module information.
func (Guard) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_guard",
		New: func() caddy.Module { return new(Guard) },
	}
}

// Provision works out the installation's hop value.
func (g *Guard) Provision(caddy.Context) error {
	g.hop = hopValue(g.Token)
	return nil
}

// Validate checks the handler has a token to work the hop value out from.
func (g *Guard) Validate() error {
	if g.Token == "" {
		return fmt.Errorf("nicetry_guard needs a token")
	}
	return nil
}

// hopValue is the first 16 hex characters of sha256("nicetry-hop:" + token).
func hopValue(token string) string {
	sum := sha256.Sum256([]byte("nicetry-hop:" + token))
	return hex.EncodeToString(sum[:])[:16]
}

func (g *Guard) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	for _, value := range r.Header.Values(hopHeader) {
		if value == g.hop {
			g.refused(r, "loop")
			return caddyhttp.Error(http.StatusLoopDetected, fmt.Errorf("the request came from this edge: its origin points back at the edge"))
		}
	}
	r.Header.Del(hopHeader)

	if hasDotSegments(r.URL.Path) {
		g.refused(r, "dot_segments")
		return caddyhttp.Error(http.StatusBadRequest, fmt.Errorf("path has dot-segments"))
	}

	caddyhttp.SetVar(r.Context(), hopVar, g.hop)
	return next.ServeHTTP(w, r)
}

// refused logs a refusal as blocked, with why as its bot_tag.
func (g *Guard) refused(r *http.Request, why string) {
	caddyhttp.SetVar(r.Context(), "bot_tag", why)
	if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
		extra.Add(zap.String("action", "blocked"))
	}
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_guard {
//	    token <token>
//	}
func (g *Guard) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "token":
			if !d.Args(&g.Token) {
				return d.ArgErr()
			}
		default:
			return d.Errf("unknown nicetry_guard option %q", d.Val())
		}
	}
	return nil
}

func parseGuardCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	g := new(Guard)
	err := g.UnmarshalCaddyfile(h.Dispenser)
	return g, err
}

var (
	_ caddy.Provisioner           = (*Guard)(nil)
	_ caddy.Validator             = (*Guard)(nil)
	_ caddyhttp.MiddlewareHandler = (*Guard)(nil)
	_ caddyfile.Unmarshaler       = (*Guard)(nil)
)
