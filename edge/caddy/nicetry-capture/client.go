package nicetrycapture

import (
	"fmt"
	"net"
	"net/http"
	"net/netip"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(ClientIP{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_client", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		c := new(ClientIP)
		return c, c.UnmarshalCaddyfile(h.Dispenser)
	})
}

// cloudflareRanges are the addresses Cloudflare connects from, as published at
// https://www.cloudflare.com/ips-v4 and /ips-v6 (checked 2026-10-11; they rarely change).
var cloudflareRanges = []string{
	"173.245.48.0/20", "103.21.244.0/22", "103.22.200.0/22", "103.31.4.0/22", "141.101.64.0/18",
	"108.162.192.0/18", "190.93.240.0/20", "188.114.96.0/20", "197.234.240.0/22", "198.41.128.0/17",
	"162.158.0.0/15", "104.16.0.0/13", "104.24.0.0/14", "172.64.0.0/13", "131.0.72.0/22",
	"2400:cb00::/32", "2606:4700::/32", "2803:f800::/32", "2405:b500::/32", "2405:8100::/32",
	"2a06:98c0::/29", "2c0f:f248::/32",
}

// ClientIP is the nicetry_client handler: it works out who the visitor really is when a site sits
// behind Cloudflare, where every connection comes from one of Cloudflare's addresses and the visitor's
// own is in CF-Connecting-IP. That header is believed only on connections from Cloudflare's published
// ranges, since anyone can send it straight to the edge. It sets:
//
//	nicetry_client_ip  the visitor's address (the connection's own unless Cloudflare vouched for another)
//
// and, for Cloudflare traffic, adds visitor_ip and via=cloudflare to the access log, so NiceTryBot
// records the visitor rather than Cloudflare (and knows the TLS fingerprints are Cloudflare's).
//
// It changes nothing the origin sees: X-Forwarded-For stays exactly as Caddy builds it.
type ClientIP struct {
	// Trust replaces the Cloudflare ranges, e.g. for tests or a different CDN.
	Trust []string `json:"trust,omitempty"`

	trusted []netip.Prefix
}

// CaddyModule returns the Caddy module information.
func (ClientIP) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_client",
		New: func() caddy.Module { return new(ClientIP) },
	}
}

// Provision parses the trusted ranges.
func (c *ClientIP) Provision(caddy.Context) error {
	ranges := c.Trust
	if len(ranges) == 0 {
		ranges = cloudflareRanges
	}
	c.trusted = make([]netip.Prefix, 0, len(ranges))
	for _, cidr := range ranges {
		prefix, err := netip.ParsePrefix(cidr)
		if err != nil {
			return fmt.Errorf("nicetry_client: bad range %q: %w", cidr, err)
		}
		c.trusted = append(c.trusted, prefix.Masked())
	}
	return nil
}

func (c *ClientIP) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	visitor, viaCloudflare := c.visitor(r)
	caddyhttp.SetVar(r.Context(), "nicetry_client_ip", visitor)

	if viaCloudflare {
		if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
			extra.Add(zap.String("visitor_ip", visitor))
			extra.Add(zap.String("via", "cloudflare"))
		}
	}
	return next.ServeHTTP(w, r)
}

// visitor returns the visitor's address, and whether Cloudflare vouched for it.
func (c *ClientIP) visitor(r *http.Request) (string, bool) {
	remote := r.RemoteAddr
	if host, _, err := net.SplitHostPort(remote); err == nil {
		remote = host
	}
	peer, err := netip.ParseAddr(remote)
	if err != nil {
		return remote, false
	}
	peer = peer.Unmap()

	if !c.isTrusted(peer) {
		return peer.String(), false
	}
	claimed, err := netip.ParseAddr(r.Header.Get("CF-Connecting-IP"))
	if err != nil {
		return peer.String(), false
	}
	return claimed.Unmap().String(), true
}

func (c *ClientIP) isTrusted(addr netip.Addr) bool {
	for _, prefix := range c.trusted {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_client [{
//	    trust <cidr...>
//	}]
func (c *ClientIP) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "trust":
			c.Trust = append(c.Trust, d.RemainingArgs()...)
			if len(c.Trust) == 0 {
				return d.ArgErr()
			}
		default:
			return d.Errf("unknown nicetry_client option %q", d.Val())
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*ClientIP)(nil)
	_ caddyhttp.MiddlewareHandler = (*ClientIP)(nil)
	_ caddyfile.Unmarshaler       = (*ClientIP)(nil)
)
