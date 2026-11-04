package nicetrycapture

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
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
	caddy.RegisterModule(Verdict{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_verdict", func(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
		v := new(Verdict)
		return v, v.UnmarshalCaddyfile(h.Dispenser)
	})
}

// verdictHeader carries NiceTryBot's verdict on a client to the origin, on the request only: a visitor's
// own copy is always removed, so the origin can trust it (it only takes traffic through the edge).
const verdictHeader = "X-NiceTry-Verdict"

const verdictMaxPayload = 16 << 20

// Verdict is the nicetry_verdict handler. It polls NiceTryBot for its platform-wide verdicts on client
// IPs (each with the header value to send) and, on every request, replaces X-NiceTry-Verdict with the
// requesting client's, if it has one. If NiceTryBot can't be reached the last list stays.
type Verdict struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	verdicts *verdictMap
}

// CaddyModule returns the Caddy module information.
func (Verdict) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_verdict",
		New: func() caddy.Module { return new(Verdict) },
	}
}

// Provision starts (or joins) the verdict poller.
func (v *Verdict) Provision(caddy.Context) error {
	if v.Interval <= 0 {
		v.Interval = caddy.Duration(30 * time.Second)
	}
	v.verdicts = sharedVerdicts(v.Endpoint, v.Token, time.Duration(v.Interval))
	return nil
}

// Validate checks the handler has an endpoint and a token.
func (v *Verdict) Validate() error {
	if v.Endpoint == "" || v.Token == "" {
		return fmt.Errorf("nicetry_verdict needs endpoint and token")
	}
	return nil
}

func (v *Verdict) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	r.Header.Del(verdictHeader)
	if value := v.verdicts.lookup(requestIP(r)); value != "" {
		r.Header.Set(verdictHeader, value)
	}
	return next.ServeHTTP(w, r)
}

type verdictMap struct {
	current atomic.Pointer[map[string]string]

	endpoint, token string
	etag            string
}

var (
	verdictMapsMu sync.Mutex
	verdictMaps   = map[string]*verdictMap{}
)

// sharedVerdicts returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedVerdicts(endpoint, token string, interval time.Duration) *verdictMap {
	verdictMapsMu.Lock()
	defer verdictMapsMu.Unlock()

	key := endpoint + "\x00" + token
	if m, ok := verdictMaps[key]; ok {
		return m
	}

	m := &verdictMap{endpoint: endpoint, token: token}
	m.current.Store(&map[string]string{})
	verdictMaps[key] = m
	go m.poll(interval)
	return m
}

func (m *verdictMap) lookup(ip string) string {
	return (*m.current.Load())[ip]
}

func (m *verdictMap) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	for {
		if err := m.refresh(client); err != nil {
			caddy.Log().Named("nicetry_verdict").Warn("keeping the last verdicts", zap.Error(err))
		}
		time.Sleep(interval)
	}
}

// refresh asks NiceTryBot for the standing verdicts. Anything but a good answer leaves the last list.
func (m *verdictMap) refresh(client *http.Client) error {
	req, err := http.NewRequest(http.MethodGet, m.endpoint, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+m.token)
	req.Header.Set("Accept", "application/json")
	if m.etag != "" {
		req.Header.Set("If-None-Match", m.etag)
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
		return fmt.Errorf("verdicts returned %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, verdictMaxPayload+1))
	if err != nil {
		return err
	}
	if len(raw) > verdictMaxPayload {
		return fmt.Errorf("verdicts answer is over %d bytes", verdictMaxPayload)
	}

	verdicts, err := parseVerdicts(raw)
	if err != nil {
		return err
	}
	m.current.Store(&verdicts)
	m.etag = resp.Header.Get("ETag")
	return nil
}

// parseVerdicts turns NiceTryBot's answer into a map of client IP to header value. The answer must name
// its verdicts (an empty list means none stands). A value that isn't plain printable ASCII, or is too
// long for a header, is skipped.
func parseVerdicts(raw []byte) (map[string]string, error) {
	var payload struct {
		Verdicts *[]struct {
			IP     string `json:"ip"`
			Header string `json:"header"`
		} `json:"verdicts"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	if payload.Verdicts == nil {
		return nil, fmt.Errorf("verdicts answer has no verdicts list")
	}

	verdicts := make(map[string]string, len(*payload.Verdicts))
	for _, v := range *payload.Verdicts {
		if v.IP != "" && isHeaderSafe(v.Header) {
			verdicts[v.IP] = v.Header
		}
	}
	return verdicts, nil
}

func isHeaderSafe(value string) bool {
	if value == "" || len(value) > 1024 {
		return false
	}
	for i := 0; i < len(value); i++ {
		if value[i] < 0x20 || value[i] > 0x7e {
			return false
		}
	}
	return true
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_verdict {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (v *Verdict) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			v.Endpoint = raw
		case "token":
			v.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			v.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_verdict option %q", key)
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*Verdict)(nil)
	_ caddy.Validator             = (*Verdict)(nil)
	_ caddyhttp.MiddlewareHandler = (*Verdict)(nil)
	_ caddyfile.Unmarshaler       = (*Verdict)(nil)
)
