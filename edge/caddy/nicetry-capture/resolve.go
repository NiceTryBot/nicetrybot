package nicetrycapture

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"sync"
	"time"

	"github.com/caddyserver/caddy/v2"
	"go.uber.org/zap"
)

const (
	// A host the app placed is kept until the next poll lists it anyway.
	resolvedTTL = 10 * time.Minute
	// A host nobody claims is not asked about again for a minute, so a scanner can't make every request
	// a round trip to the app. Errors are retried sooner.
	declinedTTL = time.Minute
	failedTTL   = 10 * time.Second
	// At most this many lookups in flight; any more wait their turn.
	maxResolving = 16
)

var hostnamePattern = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$`)

// originResolver asks the app where a host it hasn't polled yet should go, once per host at a time.
type originResolver struct {
	endpoint string
	token    string
	client   *http.Client
	slots    chan struct{}

	mu       sync.Mutex
	answers  map[string]resolvedHost
	inFlight map[string]*resolveCall
}

type resolvedHost struct {
	route *originRoute
	until time.Time
}

type resolveCall struct {
	done  chan struct{}
	route *originRoute
}

var (
	resolversMu sync.Mutex
	resolvers   = map[string]*originResolver{}
)

// sharedResolver returns one resolver per endpoint and token, shared by every site and kept across reloads.
func sharedResolver(endpoint, token string) *originResolver {
	resolversMu.Lock()
	defer resolversMu.Unlock()

	key := endpoint + "\x00" + token
	if r, ok := resolvers[key]; ok {
		return r
	}

	r := newResolver(endpoint, token, &http.Client{Timeout: 5 * time.Second})
	resolvers[key] = r
	return r
}

func newResolver(endpoint, token string, client *http.Client) *originResolver {
	return &originResolver{
		endpoint: endpoint,
		token:    token,
		client:   client,
		slots:    make(chan struct{}, maxResolving),
		answers:  map[string]resolvedHost{},
		inFlight: map[string]*resolveCall{},
	}
}

// lookup returns the host's route, asking the app if no recent answer is remembered. Concurrent
// requests for the same host share one lookup.
func (r *originResolver) lookup(host string) (originRoute, bool) {
	if !hostnamePattern.MatchString(host) {
		return originRoute{}, false
	}

	r.mu.Lock()
	if answer, ok := r.answers[host]; ok && time.Now().Before(answer.until) {
		r.mu.Unlock()
		return derefRoute(answer.route)
	}
	call, waiting := r.inFlight[host]
	if !waiting {
		call = &resolveCall{done: make(chan struct{})}
		r.inFlight[host] = call
	}
	r.mu.Unlock()

	if waiting {
		<-call.done
		return derefRoute(call.route)
	}

	route, ttl := r.fetch(host)
	call.route = route

	r.mu.Lock()
	r.answers[host] = resolvedHost{route: route, until: time.Now().Add(ttl)}
	delete(r.inFlight, host)
	r.pruneLocked()
	r.mu.Unlock()
	close(call.done)

	return derefRoute(route)
}

func (r *originResolver) fetch(host string) (*originRoute, time.Duration) {
	r.slots <- struct{}{}
	defer func() { <-r.slots }()

	logger := caddy.Log().Named("nicetry_origins")

	req, err := http.NewRequest(http.MethodGet, r.endpoint+"?host="+url.QueryEscape(host), nil)
	if err != nil {
		return nil, failedTTL
	}
	req.Header.Set("Authorization", "Bearer "+r.token)
	req.Header.Set("Accept", "application/json")

	resp, err := r.client.Do(req)
	if err != nil {
		logger.Warn("couldn't resolve a new host", zap.String("host", host), zap.Error(err))
		return nil, failedTTL
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return nil, declinedTTL
	default:
		logger.Warn("couldn't resolve a new host", zap.String("host", host), zap.Error(fmt.Errorf("resolve returned %d", resp.StatusCode)))
		return nil, failedTTL
	}

	var route originRoute
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64<<10)).Decode(&route); err != nil || !validOrigin(route) {
		return nil, failedTTL
	}
	logger.Info("resolved a new host", zap.String("host", host))
	return &route, resolvedTTL
}

// pruneLocked drops expired answers once the table gets big, so random hostnames can't grow it forever.
func (r *originResolver) pruneLocked() {
	if len(r.answers) < 10000 {
		return
	}
	now := time.Now()
	for host, answer := range r.answers {
		if now.After(answer.until) {
			delete(r.answers, host)
		}
	}
}

func derefRoute(route *originRoute) (originRoute, bool) {
	if route == nil {
		return originRoute{}, false
	}
	return *route, true
}
