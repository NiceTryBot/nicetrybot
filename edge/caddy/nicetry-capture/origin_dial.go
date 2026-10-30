package nicetrycapture

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/netip"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp/reverseproxy"
)

func init() {
	caddy.RegisterModule(OriginTransport{})
}

// OriginTransport is reverse_proxy's http transport for sites' origins (transport nicetry_origin, with
// every option of transport http), which only connects to addresses on the public internet. A team
// chooses its origin, so without this one could have the edge reach what only the edge's own network
// can: a cloud's metadata service (169.254.169.254), the app, Redis, another container. The app refuses
// such origins when they're saved, but a hostname can change what it resolves to afterwards.
//
// The check is made when a connection is opened, on the very address it opens it to: the origin's
// hostname is resolved here (answers kept for a short while, one lookup at a time per name), the
// addresses that aren't public are dropped, and the connection goes to one that's left; with none
// left, the request fails like an origin that can't be reached (nginx's 502). Nothing else changes: the
// connection is still the hostname's as far as the transport knows, so it keeps its own pool of
// connections, its TLS server name and its Host header. (Swapping the upstream for an IP address would
// pool connections by address and send one site's requests down another site's TLS connection to the
// same CDN.) Reused connections cost nothing more.
//
// NICETRY_ALLOW_PRIVATE_ORIGINS=true (or 1) turns the check off, for a self-hosted edge whose origins
// really are on its own private network.
type OriginTransport struct {
	reverseproxy.HTTPTransport
}

// CaddyModule returns the Caddy module information.
func (OriginTransport) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.reverse_proxy.transport.nicetry_origin",
		New: func() caddy.Module { return new(OriginTransport) },
	}
}

// Provision sets up the http transport, then has it dial only public addresses.
func (t *OriginTransport) Provision(ctx caddy.Context) error {
	if err := t.HTTPTransport.Provision(ctx); err != nil {
		return err
	}
	if !allowPrivateOrigins() {
		dialPublicOnly(t.Transport, sharedPublicLookup)
	}
	return nil
}

// UnmarshalCaddyfile reads the same options as transport http.
func (t *OriginTransport) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	return t.HTTPTransport.UnmarshalCaddyfile(d)
}

// allowPrivateOrigins reads NICETRY_ALLOW_PRIVATE_ORIGINS.
func allowPrivateOrigins() bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv("NICETRY_ALLOW_PRIVATE_ORIGINS"))) {
	case "true", "1":
		return true
	}
	return false
}

// errPrivateOrigin is why a connection to an origin with no public address is refused.
var errPrivateOrigin = errors.New("origin has no public address")

// dialPublicOnly wraps the transport's dialers so each connection goes to a public address of the host
// it's for. reverse_proxy dials the address in the request's dial info (the transport's own argument
// only matters when there's none, or for a proxy), so that's where the checked address goes.
func dialPublicOnly(rt *http.Transport, lookup *publicLookup) {
	pin := func(dial func(context.Context, string, string) (net.Conn, error)) func(context.Context, string, string) (net.Conn, error) {
		return func(ctx context.Context, network, addr string) (net.Conn, error) {
			info, hasInfo := reverseproxy.GetDialInfo(ctx)
			target := addr
			if hasInfo {
				if !strings.HasPrefix(info.Network, "tcp") {
					return nil, fmt.Errorf("%w: %s is not a TCP address", errPrivateOrigin, info.Network)
				}
				target = info.Address
			}
			host, port, err := net.SplitHostPort(target)
			if err != nil {
				return nil, err
			}
			addrs, err := lookup.public(ctx, host)
			if err != nil {
				return nil, err
			}

			// Like a dialer given the hostname: each address in turn until one answers.
			var lastErr error
			for _, ip := range addrs {
				checked := net.JoinHostPort(ip.String(), port)
				dialCtx, dialAddr := ctx, addr
				if hasInfo {
					pinned := info
					pinned.Address, pinned.Host = checked, ip.String()
					dialCtx = context.WithValue(ctx, dialInfoCtxKey, pinned)
				} else {
					dialAddr = checked
				}
				conn, err := dial(dialCtx, network, dialAddr)
				if err == nil {
					return conn, nil
				}
				lastErr = err
				if ctx.Err() != nil {
					break
				}
			}
			return nil, lastErr
		}
	}

	if rt.DialContext != nil {
		rt.DialContext = pin(rt.DialContext)
	}
	// Set when the TLS server name is a placeholder (it is for origins): it dials with the dial info too.
	if rt.DialTLSContext != nil {
		rt.DialTLSContext = pin(rt.DialTLSContext)
	}
}

// dialInfoCtxKey is where reverse_proxy keeps a request's dial info (reverseproxy.GetDialInfo reads it).
// The tests check this still reaches reverse_proxy's dialer.
const dialInfoCtxKey caddy.CtxKey = "reverse_proxy.dial_info"

// publicLookup resolves origin hostnames to their public addresses, keeping each answer briefly.
type publicLookup struct {
	resolve func(ctx context.Context, host string) ([]netip.Addr, error)
	ttl     time.Duration
	failTTL time.Duration

	mu      sync.Mutex
	answers map[string]*lookupAnswer
}

// lookupAnswer is one hostname's answer. done closes once it's in, so requests asking at the same time
// wait for the one lookup.
type lookupAnswer struct {
	done    chan struct{}
	addrs   []netip.Addr
	err     error
	expires time.Time
}

// publicLookupMaxNames bounds the answers kept; expired ones are swept once there are more.
const publicLookupMaxNames = 10_000

var sharedPublicLookup = newPublicLookup(func(ctx context.Context, host string) ([]netip.Addr, error) {
	return net.DefaultResolver.LookupNetIP(ctx, "ip", host)
})

func newPublicLookup(resolve func(context.Context, string) ([]netip.Addr, error)) *publicLookup {
	return &publicLookup{resolve: resolve, ttl: 30 * time.Second, failTTL: 5 * time.Second, answers: map[string]*lookupAnswer{}}
}

// public returns the host's public addresses, IPv4 first, or errPrivateOrigin when it has none. An IP
// address is checked as it is.
func (l *publicLookup) public(ctx context.Context, host string) ([]netip.Addr, error) {
	if ip, err := netip.ParseAddr(host); err == nil {
		if !publicAddress(ip) {
			return nil, fmt.Errorf("%w: %s", errPrivateOrigin, host)
		}
		return []netip.Addr{ip.Unmap()}, nil
	}

	answer := l.answer(host)
	select {
	case <-answer.done:
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	if answer.err != nil {
		return nil, answer.err
	}
	if len(answer.addrs) == 0 {
		return nil, fmt.Errorf("%w: %s", errPrivateOrigin, host)
	}
	return answer.addrs, nil
}

// answer is the host's current answer, looked up now (by the first to ask) when there's none or it
// has expired.
func (l *publicLookup) answer(host string) *lookupAnswer {
	key := strings.ToLower(strings.TrimSuffix(host, "."))
	now := time.Now()

	l.mu.Lock()
	answer, ok := l.answers[key]
	if ok && (!answer.ready() || now.Before(answer.expires)) {
		l.mu.Unlock()
		return answer
	}
	if len(l.answers) >= publicLookupMaxNames {
		for name, a := range l.answers {
			if a.ready() && !now.Before(a.expires) {
				delete(l.answers, name)
			}
		}
	}
	answer = &lookupAnswer{done: make(chan struct{})}
	l.answers[key] = answer
	l.mu.Unlock()

	// Not tied to the asking request: others may be waiting on this lookup when it goes away. As long as
	// reverse_proxy gives a dial (3 s), which used to include the lookup.
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	addrs, err := l.resolve(ctx, key)
	answer.addrs = publicOnly(addrs)
	answer.err = err
	if err != nil {
		answer.expires = time.Now().Add(l.failTTL)
	} else {
		answer.expires = time.Now().Add(l.ttl)
	}
	close(answer.done)
	return answer
}

func (a *lookupAnswer) ready() bool {
	select {
	case <-a.done:
		return true
	default:
		return false
	}
}

// publicOnly keeps the public addresses, IPv4 first (as most dialers prefer them where IPv6 isn't
// routed), otherwise in the resolver's order.
func publicOnly(addrs []netip.Addr) []netip.Addr {
	var kept []netip.Addr
	for _, ip := range addrs {
		if publicAddress(ip) {
			kept = append(kept, ip.Unmap())
		}
	}
	sort.SliceStable(kept, func(i, j int) bool { return kept[i].Is4() && !kept[j].Is4() })
	return kept
}

// notPublic are the ranges publicAddress refuses beyond what netip names: carrier-grade NAT,
// benchmarking, the IETF's and documentation's own, reserved, and IPv6's discard and documentation
// ranges, and the old site-local fec0::/10. 0.0.0.0/8 is "this network", which Linux connects to as
// the machine itself, and 240.0.0.0/4 takes in the broadcast address.
var notPublic = func() []netip.Prefix {
	var prefixes []netip.Prefix
	for _, p := range []string{
		"0.0.0.0/8", "100.64.0.0/10", "192.0.0.0/24", "192.0.2.0/24", "198.18.0.0/15", "198.51.100.0/24",
		"203.0.113.0/24", "240.0.0.0/4", "::/96", "64:ff9b:1::/48", "100::/64", "2001:db8::/32", "fec0::/10",
	} {
		prefixes = append(prefixes, netip.MustParsePrefix(p))
	}
	return prefixes
}()

// nat64 holds IPv4 addresses translated to IPv6 (RFC 6052), which are checked as the IPv4 address.
var nat64 = netip.MustParsePrefix("64:ff9b::/96")

// publicAddress reports whether ip is on the public internet: not private (RFC 1918, fc00::/7),
// loopback, link-local (169.254.0.0/16, fe80::/10), multicast, unspecified, or in notPublic, and not one
// of those written as IPv6.
func publicAddress(ip netip.Addr) bool {
	ip = ip.Unmap()
	if nat64.Contains(ip) {
		b := ip.As16()
		ip = netip.AddrFrom4([4]byte{b[12], b[13], b[14], b[15]})
	}
	if !ip.IsValid() || ip.IsUnspecified() || ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() ||
		ip.IsMulticast() || ip.IsLinkLocalMulticast() || ip.IsInterfaceLocalMulticast() {
		return false
	}
	for _, p := range notPublic {
		if p.Contains(ip) {
			return false
		}
	}
	return true
}

var (
	_ caddy.Provisioner     = (*OriginTransport)(nil)
	_ caddyfile.Unmarshaler = (*OriginTransport)(nil)
	_ http.RoundTripper     = (*OriginTransport)(nil)
)
