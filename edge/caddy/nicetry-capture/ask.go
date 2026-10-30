package nicetrycapture

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddytls"
)

func init() {
	caddy.RegisterModule(AskPermission{})
}

// AskPermission is Caddy's on-demand TLS "ask" (GET <endpoint>?domain=<name>, any 2xx allows the
// certificate) with the edge token as a bearer token, for an app that only answers the edge:
//
//	on_demand_tls {
//	    permission nicetry_ask {
//	        endpoint <url>
//	        token    <token>
//	    }
//	}
//
// Caddy's own ask could only carry the token in its URL, and Caddy writes that URL into its errors,
// which it logs at error level (an app that can't be reached, a renewal the app turns down). Here the
// token is only ever in a header, so no log the edge keeps has it.
type AskPermission struct {
	Endpoint string `json:"endpoint,omitempty"`
	Token    string `json:"token,omitempty"`

	endpoint *url.URL
	client   *http.Client
}

// CaddyModule returns the Caddy module information.
func (AskPermission) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "tls.permission.nicetry_ask",
		New: func() caddy.Module { return new(AskPermission) },
	}
}

// Provision reads the endpoint, and sets up a client that behaves like Caddy's ask: 10 seconds, no
// redirects followed.
func (p *AskPermission) Provision(caddy.Context) error {
	endpoint, err := url.Parse(p.Endpoint)
	if err != nil {
		return fmt.Errorf("nicetry_ask endpoint: %v", err)
	}
	p.endpoint = endpoint
	p.client = &http.Client{
		Timeout: 10 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return fmt.Errorf("following http redirects is not allowed")
		},
	}
	return nil
}

// Validate checks there's somewhere to ask and a token to ask with.
func (p *AskPermission) Validate() error {
	if p.Endpoint == "" || p.Token == "" {
		return fmt.Errorf("nicetry_ask needs an endpoint and a token")
	}
	return nil
}

// CertificateAllowed asks the endpoint about name. Its errors name the endpoint (never the token), and
// a refusal wraps caddytls.ErrPermissionDenied, so Caddy tells it apart from a failure to ask.
func (p *AskPermission) CertificateAllowed(ctx context.Context, name string) error {
	ask := *p.endpoint
	query := ask.Query()
	query.Set("domain", name)
	ask.RawQuery = query.Encode()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, ask.String(), nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+p.Token)

	resp, err := p.client.Do(req)
	if err != nil {
		return fmt.Errorf("asking %s whether %s may have a certificate: %v", p.Endpoint, name, err)
	}
	_ = resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return fmt.Errorf("%s: %w (%s answered %d)", name, caddytls.ErrPermissionDenied, p.Endpoint, resp.StatusCode)
	}
	return nil
}

// UnmarshalCaddyfile sets up the module from Caddyfile tokens (see AskPermission).
func (p *AskPermission) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		switch d.Val() {
		case "endpoint":
			if !d.Args(&p.Endpoint) {
				return d.ArgErr()
			}
		case "token":
			if !d.Args(&p.Token) {
				return d.ArgErr()
			}
		default:
			return d.Errf("unknown nicetry_ask option %q", d.Val())
		}
	}
	return nil
}

var (
	_ caddy.Provisioner           = (*AskPermission)(nil)
	_ caddy.Validator             = (*AskPermission)(nil)
	_ caddytls.OnDemandPermission = (*AskPermission)(nil)
	_ caddyfile.Unmarshaler       = (*AskPermission)(nil)
)
