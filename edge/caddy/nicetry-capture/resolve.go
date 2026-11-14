package nicetrycapture

import (
	"context"
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
	// Past this many hosts waiting on an answer, a new host is turned away instead of queued, so a flood
	// of made-up Host headers can't pile up behind the slots.
	maxPending = 64
	// How long a lookup waits for a free slot before giving up on this request.
	slotWait = 250 * time.Millisecond
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
// requests for the same host share one lookup. A lookup is dropped when the visitor goes away, when
// no slot frees up in time, or when too many hosts are already waiting; none of those are remembered.
func (r *originResolver) lookup(ctx context.Context, host string) (originRoute, bool) {
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
		if len(r.inFlight) >= maxPending {
			r.mu.Unlock()
			return originRoute{}, false
		}
		call = &resolveCall{done: make(chan struct{})}
		r.inFlight[host] = call
	}
	r.mu.Unlock()

	if waiting {
		select {
		case <-call.done:
			return derefRoute(call.route)
		case <-ctx.Done():
			return originRoute{}, false
		}
	}

	route, ttl, answered := r.fetch(ctx, host)
	call.route = route

	r.mu.Lock()
	if answered {
		r.answers[host] = resolvedHost{route: route, until: time.Now().Add(ttl)}
		r.pruneLocked()
	}
	delete(r.inFlight, host)
	r.mu.Unlock()
	close(call.done)

	return derefRoute(route)
}

// fetch asks the app about the host. answered is false when the app was never asked or the visitor
// left mid-ask, so the caller doesn't remember a non-answer.
func (r *originResolver) fetch(ctx context.Context, host string) (route *originRoute, ttl time.Duration, answered bool) {
	wait := time.NewTimer(slotWait)
	defer wait.Stop()
	select {
	case r.slots <- struct{}{}:
	case <-wait.C:
		return nil, 0, false
	case <-ctx.Done():
		return nil, 0, false
	}
	defer func() { <-r.slots }()

	logger := caddy.Log().Named("nicetry_origins")

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, r.endpoint+"?host="+url.QueryEscape(host), nil)
	if err != nil {
		return nil, failedTTL, true
	}
	req.Header.Set("Authorization", "Bearer "+r.token)
	req.Header.Set("Accept", "application/json")

	resp, err := r.client.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return nil, 0, false
		}
		logger.Warn("couldn't resolve a new host", zap.String("host", host), zap.Error(err))
		return nil, failedTTL, true
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return nil, declinedTTL, true
	default:
		logger.Warn("couldn't resolve a new host", zap.String("host", host), zap.Error(fmt.Errorf("resolve returned %d", resp.StatusCode)))
		return nil, failedTTL, true
	}

	var found originRoute
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64<<10)).Decode(&found); err != nil || !validOrigin(found) {
		return nil, failedTTL, true
	}
	logger.Info("resolved a new host", zap.String("host", host))
	return &found, resolvedTTL, true
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
