package nicetrycapture

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
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
	caddy.RegisterModule(LiveTail{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_tail", parseLiveTailCaddyfile)
}

// LiveTail is the nicetry_tail handler: it marks the access log line (tail: true) of every request to a
// hostname someone is watching live in NiceTryBot, polled from the endpoint. Vector sends only marked
// lines to the app every couple of seconds; everything still goes to S3. Usually nobody is watching,
// the list is empty, and nothing is marked.
type LiveTail struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	hosts *hostSet
}

// CaddyModule returns the Caddy module information.
func (LiveTail) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_tail",
		New: func() caddy.Module { return new(LiveTail) },
	}
}

// Provision starts (or joins) the poller for the endpoint.
func (t *LiveTail) Provision(caddy.Context) error {
	if t.Interval <= 0 {
		t.Interval = caddy.Duration(10 * time.Second)
	}
	t.hosts = sharedHostSet(t.Endpoint, t.Token, time.Duration(t.Interval))
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (t *LiveTail) Validate() error {
	if t.Endpoint == "" || t.Token == "" {
		return fmt.Errorf("nicetry_tail needs an endpoint and a token")
	}
	return nil
}

func (t *LiveTail) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	if t.hosts.has(hostOnly(r.Host)) {
		if extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields); ok {
			extra.Add(zap.Bool("tail", true))
		}
	}
	return next.ServeHTTP(w, r)
}

type hostSet struct {
	current atomic.Pointer[map[string]struct{}]
}

func (h *hostSet) has(host string) bool {
	hosts := *h.current.Load()
	if len(hosts) == 0 {
		return false
	}
	_, ok := hosts[strings.ToLower(host)]
	return ok
}

var (
	hostSetsMu sync.Mutex
	hostSets   = map[string]*hostSet{}
)

// sharedHostSet returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedHostSet(endpoint, token string, interval time.Duration) *hostSet {
	hostSetsMu.Lock()
	defer hostSetsMu.Unlock()

	key := endpoint + "\x00" + token
	if h, ok := hostSets[key]; ok {
		return h
	}

	h := &hostSet{}
	h.current.Store(&map[string]struct{}{})
	hostSets[key] = h
	go h.poll(endpoint, token, interval)
	return h
}

func (h *hostSet) poll(endpoint, token string, interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	logger := caddy.Log().Named("nicetry_tail")
	last := -1

	for {
		hosts, err := fetchTailedHosts(client, endpoint, token)
		if err != nil {
			logger.Warn("keeping the last live tail hosts", zap.Error(err))
		} else {
			h.current.Store(&hosts)
			if len(hosts) != last {
				logger.Info("live tail hosts updated", zap.Int("hosts", len(hosts)))
				last = len(hosts)
			}
		}
		time.Sleep(interval)
	}
}

func fetchTailedHosts(client *http.Client, endpoint, token string) (map[string]struct{}, error) {
	req, err := http.NewRequest(http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")

	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("live tail returned %d", resp.StatusCode)
	}

	var payload struct {
		Hosts []string `json:"hosts"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&payload); err != nil {
		return nil, err
	}

	hosts := make(map[string]struct{}, len(payload.Hosts))
	for _, host := range payload.Hosts {
		hosts[strings.ToLower(host)] = struct{}{}
	}
	return hosts, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_tail {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (t *LiveTail) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			t.Endpoint = raw
		case "token":
			t.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			t.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_tail option %q", key)
		}
	}
	return nil
}

func parseLiveTailCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	t := new(LiveTail)
	err := t.UnmarshalCaddyfile(h.Dispenser)
	return t, err
}

var (
	_ caddy.Provisioner           = (*LiveTail)(nil)
	_ caddy.Validator             = (*LiveTail)(nil)
	_ caddyhttp.MiddlewareHandler = (*LiveTail)(nil)
	_ caddyfile.Unmarshaler       = (*LiveTail)(nil)
)
