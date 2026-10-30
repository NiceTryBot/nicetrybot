package nicetrycapture

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp/reverseproxy"
)

func TestOnlyPublicAddressesArePublic(t *testing.T) {
	for addr, want := range map[string]bool{
		"93.184.215.14": true, "8.8.8.8": true, "2606:4700::6810:84e5": true, "100.63.255.255": true, "100.128.0.0": true,
		"169.254.169.254": false, "10.0.0.5": false, "172.16.0.1": false, "192.168.1.1": false, "127.0.0.1": false,
		"0.0.0.0": false, "0.1.2.3": false, "100.64.0.1": false, "100.127.255.255": false, "198.18.0.1": false,
		"198.19.255.255": false, "224.0.0.1": false, "240.0.0.1": false, "255.255.255.255": false, "192.0.2.1": false,
		"::1": false, "::": false, "fe80::1": false, "fc00::1": false, "fd12:3456::1": false, "ff02::1": false,
		"::ffff:169.254.169.254": false, "::ffff:10.0.0.1": false, "::ffff:93.184.215.14": true,
		"64:ff9b::a9fe:a9fe": false, "64:ff9b::5db8:d70e": true, "::7f00:1": false, "2001:db8::1": false,
	} {
		if got := publicAddress(netip.MustParseAddr(addr)); got != want {
			t.Errorf("%s: public %v, want %v", addr, got, want)
		}
	}
}

// fakeResolver answers from a table, counting the lookups.
type fakeResolver struct {
	answers map[string][]string
	lookups atomic.Int32
	delay   time.Duration
}

func (f *fakeResolver) lookup() *publicLookup {
	return newPublicLookup(func(_ context.Context, host string) ([]netip.Addr, error) {
		f.lookups.Add(1)
		time.Sleep(f.delay)
		raw, ok := f.answers[host]
		if !ok {
			return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
		}
		var addrs []netip.Addr
		for _, a := range raw {
			addrs = append(addrs, netip.MustParseAddr(a))
		}
		return addrs, nil
	})
}

func TestAHostnamesPrivateAddressesAreDroppedAndOneWithNoneLeftIsRefused(t *testing.T) {
	f := &fakeResolver{answers: map[string][]string{
		"origin.example":   {"2606:4700::6810:84e5", "10.0.0.5", "93.184.215.14"},
		"rebound.example":  {"169.254.169.254"},
		"internal.example": {"127.0.0.1", "::1"},
	}}
	l := f.lookup()

	addrs, err := l.public(context.Background(), "Origin.Example.")
	if err != nil || len(addrs) != 2 || addrs[0].String() != "93.184.215.14" || addrs[1].String() != "2606:4700::6810:84e5" {
		t.Fatalf("expected the public addresses, IPv4 first: %v %v", addrs, err)
	}
	for _, host := range []string{"rebound.example", "internal.example", "10.1.2.3", "::ffff:127.0.0.1"} {
		if _, err := l.public(context.Background(), host); !errors.Is(err, errPrivateOrigin) {
			t.Errorf("%s: expected a refusal, got %v", host, err)
		}
	}
	if addrs, err := l.public(context.Background(), "93.184.215.14"); err != nil || len(addrs) != 1 {
		t.Errorf("a public IP address is used as it is: %v %v", addrs, err)
	}
	if _, err := l.public(context.Background(), "missing.example"); err == nil || errors.Is(err, errPrivateOrigin) {
		t.Errorf("a name that doesn't resolve fails like before: %v", err)
	}
}

func TestAnAnswerIsKeptBrieflyAndLookedUpOnceForEveryoneAskingAtOnce(t *testing.T) {
	f := &fakeResolver{answers: map[string][]string{"origin.example": {"93.184.215.14"}}, delay: 20 * time.Millisecond}
	l := f.lookup()

	var wg sync.WaitGroup
	for range 20 {
		wg.Go(func() {
			if _, err := l.public(context.Background(), "origin.example"); err != nil {
				t.Error(err)
			}
		})
	}
	wg.Wait()
	if _, err := l.public(context.Background(), "origin.example"); err != nil || f.lookups.Load() != 1 {
		t.Fatalf("expected one lookup, got %d (%v)", f.lookups.Load(), err)
	}

	l.mu.Lock()
	l.answers["origin.example"].expires = time.Now().Add(-time.Second)
	l.mu.Unlock()
	if _, _ = l.public(context.Background(), "origin.example"); f.lookups.Load() != 2 {
		t.Errorf("an expired answer should be looked up again, got %d lookups", f.lookups.Load())
	}
}

// The connection goes to the checked address: reverse_proxy's dialer reads it from the dial info, the
// transport's own argument (the hostname, its pool's key) is left alone.
func TestEachConnectionIsPinnedToTheCheckedAddress(t *testing.T) {
	f := &fakeResolver{answers: map[string][]string{"origin.example": {"10.0.0.5", "93.184.215.14"}, "private.example": {"10.0.0.5"}}}
	var dialed []string
	rt := &http.Transport{DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
		info, _ := reverseproxy.GetDialInfo(ctx)
		dialed = append(dialed, addr+" -> "+info.Address)
		client, server := net.Pipe()
		_ = server.Close()
		return client, nil
	}}
	dialPublicOnly(rt, f.lookup())

	ctx := context.WithValue(context.Background(), dialInfoCtxKey, reverseproxy.DialInfo{Network: "tcp", Address: "origin.example:443", Host: "origin.example", Port: "443"})
	conn, err := rt.DialContext(ctx, "tcp", "origin.example:443")
	if err != nil {
		t.Fatal(err)
	}
	_ = conn.Close()
	if len(dialed) != 1 || dialed[0] != "origin.example:443 -> 93.184.215.14:443" {
		t.Fatalf("dialed %v", dialed)
	}

	ctx = context.WithValue(context.Background(), dialInfoCtxKey, reverseproxy.DialInfo{Network: "tcp", Address: "private.example:80", Host: "private.example", Port: "80"})
	if _, err := rt.DialContext(ctx, "tcp", "private.example:80"); !errors.Is(err, errPrivateOrigin) || len(dialed) != 1 {
		t.Fatalf("expected a refusal without dialing, got %v (%v)", err, dialed)
	}
}

// The real transport: an origin on the edge's own machine is refused, unless private origins are allowed.
func TestTheOriginTransportRefusesAPrivateOriginUnlessAllowed(t *testing.T) {
	origin := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("internal")) }))
	defer origin.Close()
	u, _ := url.Parse(origin.URL)

	roundTrip := func(t *testing.T) (*http.Response, error) {
		ctx, cancel := caddy.NewContext(caddy.Context{Context: context.Background()})
		defer cancel()
		tr := &OriginTransport{}
		if err := tr.Provision(ctx); err != nil {
			t.Fatal(err)
		}
		defer func() { _ = tr.Cleanup() }()
		reqCtx := context.WithValue(context.Background(), dialInfoCtxKey, reverseproxy.DialInfo{Network: "tcp", Address: u.Host, Host: u.Hostname(), Port: u.Port()})
		req, _ := http.NewRequestWithContext(reqCtx, http.MethodGet, origin.URL, nil)
		return tr.RoundTrip(req)
	}

	t.Setenv("NICETRY_ALLOW_PRIVATE_ORIGINS", "")
	if resp, err := roundTrip(t); !errors.Is(err, errPrivateOrigin) {
		if resp != nil {
			_ = resp.Body.Close()
		}
		t.Fatalf("expected the private origin refused, got %v", err)
	}

	t.Setenv("NICETRY_ALLOW_PRIVATE_ORIGINS", "true")
	resp, err := roundTrip(t)
	if err != nil {
		t.Fatalf("with private origins allowed: %v", err)
	}
	_ = resp.Body.Close()
}
