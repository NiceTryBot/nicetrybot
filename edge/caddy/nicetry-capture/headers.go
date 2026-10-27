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
	caddy.RegisterModule(SiteHeaders{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_headers", parseSiteHeadersCaddyfile)
}

// SiteHeaders is the nicetry_headers handler: it answers with the Server header each domain chose in
// NiceTryBot (polled from the endpoint), overriding whatever was set further in, so a honeypot can
// look like old, unpatched software. Domains that chose nothing keep the edge's default.
type SiteHeaders struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`

	servers *serverMap
}

// CaddyModule returns the Caddy module information.
func (SiteHeaders) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_headers",
		New: func() caddy.Module { return new(SiteHeaders) },
	}
}

// Provision starts (or joins) the poller for the endpoint.
func (s *SiteHeaders) Provision(caddy.Context) error {
	if s.Interval <= 0 {
		s.Interval = caddy.Duration(30 * time.Second)
	}
	s.servers = sharedServers(s.Endpoint, s.Token, time.Duration(s.Interval))
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (s *SiteHeaders) Validate() error {
	if s.Endpoint == "" || s.Token == "" {
		return fmt.Errorf("nicetry_headers needs an endpoint and a token")
	}
	return nil
}

func (s *SiteHeaders) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	server, ok := (*s.servers.current.Load())[strings.ToLower(hostOnly(r.Host))]
	if !ok {
		return next.ServeHTTP(w, r)
	}
	return next.ServeHTTP(&serverHeaderWriter{ResponseWriter: w, server: server}, r)
}

// serverHeaderWriter sets the Server header as the response starts, after every handler further in
// (and the origin) has had its say.
type serverHeaderWriter struct {
	http.ResponseWriter
	server  string
	written bool
}

func (s *serverHeaderWriter) WriteHeader(code int) {
	if !s.written {
		s.written = true
		s.Header().Set("Server", s.server)
	}
	s.ResponseWriter.WriteHeader(code)
}

func (s *serverHeaderWriter) Write(p []byte) (int, error) {
	if !s.written {
		s.WriteHeader(http.StatusOK)
	}
	return s.ResponseWriter.Write(p)
}

// Unwrap lets http.ResponseController reach the real writer to flush.
func (s *serverHeaderWriter) Unwrap() http.ResponseWriter { return s.ResponseWriter }

type serverMap struct {
	current atomic.Pointer[map[string]string]
}

var (
	serverMapsMu sync.Mutex
	serverMaps   = map[string]*serverMap{}
)

// sharedServers returns one poller per endpoint and token, shared by every site and kept across reloads.
func sharedServers(endpoint, token string, interval time.Duration) *serverMap {
	serverMapsMu.Lock()
	defer serverMapsMu.Unlock()

	key := endpoint + "\x00" + token
	if m, ok := serverMaps[key]; ok {
		return m
	}

	m := &serverMap{}
	m.current.Store(&map[string]string{})
	serverMaps[key] = m
	go m.poll(endpoint, token, interval)
	return m
}

func (m *serverMap) poll(endpoint, token string, interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	logger := caddy.Log().Named("nicetry_headers")
	last := -1

	for {
		servers, err := fetchServers(client, endpoint, token)
		if err != nil {
			logger.Warn("keeping the last site headers", zap.Error(err))
		} else {
			m.current.Store(&servers)
			if len(servers) != last {
				logger.Info("site headers updated", zap.Int("sites", len(servers)))
				last = len(servers)
			}
		}
		time.Sleep(interval)
	}
}

func fetchServers(client *http.Client, endpoint, token string) (map[string]string, error) {
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
		return nil, fmt.Errorf("site headers returned %d", resp.StatusCode)
	}

	var payload struct {
		Sites []struct {
			Host   string `json:"host"`
			Server string `json:"server"`
		} `json:"sites"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&payload); err != nil {
		return nil, err
	}

	servers := make(map[string]string, len(payload.Sites))
	for _, site := range payload.Sites {
		// Header values must stay on one line; the app checks too, but never trust the wire.
		if site.Server != "" && !strings.ContainsAny(site.Server, "\r\n") {
			servers[strings.ToLower(site.Host)] = site.Server
		}
	}
	return servers, nil
}

// UnmarshalCaddyfile sets up the handler from Caddyfile tokens:
//
//	nicetry_headers {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	}
func (s *SiteHeaders) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			s.Endpoint = raw
		case "token":
			s.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			s.Interval = caddy.Duration(dur)
		default:
			return d.Errf("unknown nicetry_headers option %q", key)
		}
	}
	return nil
}

func parseSiteHeadersCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	s := new(SiteHeaders)
	err := s.UnmarshalCaddyfile(h.Dispenser)
	return s, err
}

var (
	_ caddy.Provisioner           = (*SiteHeaders)(nil)
	_ caddy.Validator             = (*SiteHeaders)(nil)
	_ caddyhttp.MiddlewareHandler = (*SiteHeaders)(nil)
	_ caddyfile.Unmarshaler       = (*SiteHeaders)(nil)
)
