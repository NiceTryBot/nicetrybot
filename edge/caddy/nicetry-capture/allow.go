package nicetrycapture

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Allow{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_allow", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		a := new(Allow)
		return a, a.UnmarshalCaddyfile(h.Dispenser)
	})
}

// nicetryAllowedVar is set on a request whose client is on the allow list, so the handlers that would
// hold, divert or mark it (nicetry_tarpit, nicetry_gym, nicetry_verdict) can let it straight through.
const nicetryAllowedVar = "nicetry_allowed"

const allowMaxPayload = 16 << 20

// Allow is the nicetry_allow handler. It polls NiceTryBot for the IPs and CIDR ranges the installation
// allows past every enforcement (the White List and Nice Bots add-ons) and, on every request, marks the
// ones whose client is on the list. It must run after nicetry_client (so the client's real address is
// known) and before nicetry_tarpit, nicetry_gym and nicetry_verdict. If NiceTryBot can't be reached the
// last list stays; if the handler isn't configured at all, nothing is marked and the others run as
// before.
type Allow struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	ranges *allowList
}

// CaddyModule returns the Caddy module information.
func (Allow) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_allow",
		New: func() caddy.Module { return new(Allow) },
	}
}

// Provision starts (or joins) the allow-list poller.
func (a *Allow) Provision(caddy.Context) error {
	if a.Interval <= 0 {
		a.Interval = caddy.Duration(30 * time.Second)
	}
	a.ranges = sharedAllow(a.Endpoint, a.Token, time.Duration(a.Interval))
	return nil
}

// Validate checks the handler has an endpoint and a token.
func (a *Allow) Validate() error {
	if a.Endpoint == "" || a.Token == "" {
		return fmt.Errorf("nicetry_allow needs an endpoint and a token")
	}
	return nil
}

func (a *Allow) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	if a.ranges.has(lockdownClientIP(r)) {
		caddyhttp.SetVar(r.Context(), nicetryAllowedVar, true)
	}
	return next.ServeHTTP(w, r)
}

// isAllowed reports whether nicetry_allow marked this request's client as allowed.
func isAllowed(r *http.Request) bool {
	allowed, _ := caddyhttp.GetVar(r.Context(), nicetryAllowedVar).(bool)
	return allowed
}

type allowList struct {
	current atomic.Pointer[[]netip.Prefix]

	endpoint, token string
	etag            string
}

var (
	allowListsMu sync.Mutex
	allowLists   = map[string]*allowList{}
)

// sharedAllow returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedAllow(endpoint, token string, interval time.Duration) *allowList {
	allowListsMu.Lock()
	defer allowListsMu.Unlock()

	key := endpoint + "\x00" + token
	if l, ok := allowLists[key]; ok {
		return l
	}

	l := &allowList{endpoint: endpoint, token: token}
	l.current.Store(&[]netip.Prefix{})
	allowLists[key] = l
	go l.poll(interval)
	return l
}

// has reports whether an address falls in one of the allowed ranges.
func (l *allowList) has(ip string) bool {
	addr, err := netip.ParseAddr(ip)
	if err != nil {
		return false
	}
	addr = addr.Unmap()
	for _, prefix := range *l.current.Load() {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}

// restore starts the allow list from its last good copy.
func (l *allowList) restore(store *lastGood) bool {
	ranges, ok := restoreList(store, "allow", parseAllow)
	if ok {
		l.current.Store(&ranges)
	}
	return ok
}

func (l *allowList) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	l.restore(sharedLastGood())
	for {
		if err := l.refresh(client); err != nil {
			caddy.Log().Named("nicetry_allow").Warn("keeping the last allow list", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks NiceTryBot for the allow list. Anything but a good answer leaves the last list.
func (l *allowList) refresh(client *http.Client) error {
	req, err := http.NewRequest(http.MethodGet, l.endpoint, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+l.token)
	req.Header.Set("Accept", "application/json")
	if l.etag != "" {
		req.Header.Set("If-None-Match", l.etag)
	}

	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotModified {
		return nil
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("allow feed returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, allowMaxPayload+1))
	if err != nil {
		return err
	}
	if len(raw) > allowMaxPayload {
		return fmt.Errorf("allow answer is over %d bytes", allowMaxPayload)
	}

	ranges, err := parseAllow(raw)
	if err != nil {
		return err
	}
	l.current.Store(&ranges)
	l.etag = resp.Header.Get("ETag")
	sharedLastGood().save("allow", raw)
	return nil
}

// parseAllow turns NiceTryBot's answer into the allowed prefixes. The answer must name its list (an
// empty list means nothing is allowed). An entry that isn't an address or range is skipped.
func parseAllow(raw []byte) ([]netip.Prefix, error) {
	var payload struct {
		Allow *[]string `json:"allow"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Allow == nil {
		return nil, fmt.Errorf("allow answer has no allow list")
	}

	ranges := make([]netip.Prefix, 0, len(*payload.Allow))
	for _, entry := range *payload.Allow {
		if prefix, err := parseAllowEntry(entry); err == nil {
			ranges = append(ranges, prefix)
		}
	}
	return ranges, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_allow {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (a *Allow) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			a.Endpoint = raw
		case "token":
			a.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			a.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_allow option %q", key)
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*Allow)(nil)
	_ caddy.Validator             = (*Allow)(nil)
	_ caddyhttp.MiddlewareHandler = (*Allow)(nil)
	_ caddyfile.Unmarshaler       = (*Allow)(nil)
)
