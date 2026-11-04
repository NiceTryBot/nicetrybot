// Command nicetry-blackhole drops listed clients in the kernel, before a TLS handshake or a request
// costs anything. It polls NiceTryBot for the addresses the Blackhole add-on lists and keeps an nftables
// table in step: `inet nicetry_blackhole`, hooked at prerouting before connection tracking, on the web
// ports only. It runs beside the edge rather than inside it, because it needs CAP_NET_ADMIN and Caddy
// shouldn't, and because in a container stack it has to sit in the host's network namespace.
//
// It fails towards letting traffic through:
//
//   - Every address has a timeout in the kernel, so nothing outlives its expiry if this agent dies.
//   - A 404 from the app means the add-on is off, unlicensed or gone: the whole table is removed.
//   - An unreachable app changes nothing: the last list stands until its timeouts run out.
//   - Until the first good answer it touches nothing.
//
// In Observe mode the same rules count packets without dropping them, so the list can be tried safely.
//
// `nicetry-blackhole flush` removes the table by hand, for an emergency.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/netip"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

const (
	tableName = "nicetry_blackhole"
	version   = "1.0.0"

	// maxCountersPerReport keeps one report small; what's left over goes in the next.
	maxCountersPerReport = 2000
)

// runner runs nft: the real binary in production, a fake in tests.
type runner interface {
	run(ctx context.Context, stdin string, args ...string) ([]byte, error)
}

type nftBinary struct{ path string }

func (n nftBinary) run(ctx context.Context, stdin string, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, n.path, args...)
	cmd.Stdin = strings.NewReader(stdin)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if err != nil {
		return out, fmt.Errorf("%w: %s", err, strings.TrimSpace(stderr.String()))
	}
	return out, nil
}

type counter struct {
	Address string `json:"address"`
	Packets uint64 `json:"packets"`
	Bytes   uint64 `json:"bytes"`
}

// agent keeps the kernel in step with the feed.
type agent struct {
	endpoint string
	token    string
	edge     string
	ports    string
	nft      runner
	client   *http.Client
	now      func() time.Time

	etag      string
	feed      *Feed
	lastError string
	applied   string

	// What the kernel's counters said last time, and what's been counted but not yet told to the app.
	seen    map[netip.Prefix]element
	pending map[netip.Prefix]counter
}

func newAgent(endpoint, token, edge, ports string, nft runner) *agent {
	return &agent{
		endpoint: strings.TrimRight(endpoint, "/"),
		token:    token,
		edge:     edge,
		ports:    ports,
		nft:      nft,
		client:   &http.Client{Timeout: 20 * time.Second},
		now:      time.Now,
		seen:     map[netip.Prefix]element{},
		pending:  map[netip.Prefix]counter{},
	}
}

var errAddonGone = errors.New("the add-on isn't answering: off, unlicensed or removed")

// poll asks the app for the list. A 304 keeps the one it has.
func (a *agent) poll(ctx context.Context) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, a.endpoint+"/caddy/blackhole", nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+a.token)
	req.Header.Set("Accept", "application/json")
	if a.etag != "" {
		req.Header.Set("If-None-Match", a.etag)
	}

	resp, err := a.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotModified:
		return nil
	case http.StatusNotFound:
		return errAddonGone
	default:
		return fmt.Errorf("the feed answered %d", resp.StatusCode)
	}

	var feed Feed
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64<<20)).Decode(&feed); err != nil {
		return fmt.Errorf("reading the feed: %w", err)
	}
	if feed.Entries == nil {
		return errors.New("the feed has no entries list")
	}
	a.feed = &feed
	a.etag = resp.Header.Get("ETag")
	return nil
}

// current reads what the kernel holds, or nothing if the table isn't there yet.
func (a *agent) current(ctx context.Context) (map[netip.Prefix]element, bool) {
	elements := map[netip.Prefix]element{}
	for _, set := range []string{"blocked4", "blocked6"} {
		out, err := a.nft.run(ctx, "", "-j", "list", "set", "inet", tableName, set)
		if err != nil {
			return map[netip.Prefix]element{}, false
		}
		parsed, err := parseSet(out, a.now())
		if err != nil {
			return map[netip.Prefix]element{}, false
		}
		for prefix, entry := range parsed {
			elements[prefix] = entry
		}
	}
	return elements, true
}

// apply takes the kernel to the feed, and counts what it dropped since last time.
func (a *agent) apply(ctx context.Context) error {
	if a.feed == nil {
		return nil
	}

	want := plan(*a.feed, a.now())
	if len(want.skipped) > 0 {
		log.Printf("skipped %d entries the agent won't apply: %s", len(want.skipped), strings.Join(firstFew(want.skipped), ", "))
	}

	held, present := a.current(ctx)
	a.count(held)

	// Nothing to change, and the table's still there: leave the kernel alone.
	text := script(want, held, a.ports)
	if present && text == a.applied {
		a.seen = held
		return nil
	}

	if _, err := a.nft.run(ctx, text, "-f", "-"); err != nil {
		return fmt.Errorf("applying the list: %w", err)
	}
	a.applied = text

	// A replaced element starts its counter over: take the fresh reading as the new baseline.
	after, _ := a.current(ctx)
	a.seen = after
	return nil
}

func firstFew(values []string) []string {
	if len(values) > 5 {
		return values[:5]
	}
	return values
}

// count adds what each element's counter gained since it was last read. A counter that went backwards
// was replaced, so all of it is new.
func (a *agent) count(held map[netip.Prefix]element) {
	for prefix, entry := range held {
		before, known := a.seen[prefix]
		packets, bytes := entry.packets, entry.bytes
		if known && entry.packets >= before.packets {
			packets -= before.packets
			bytes -= before.bytes
		}
		if packets == 0 {
			continue
		}
		owed := a.pending[prefix]
		owed.Address = formatPrefix(prefix)
		owed.Packets += packets
		owed.Bytes += bytes
		a.pending[prefix] = owed
	}
}

// report tells the app how things stand, with the counts it hasn't heard yet. What it can't deliver
// waits for the next one.
func (a *agent) report(ctx context.Context) error {
	counters := make([]counter, 0, min(len(a.pending), maxCountersPerReport))
	sent := make([]netip.Prefix, 0, cap(counters))
	for prefix, owed := range a.pending {
		if len(counters) == maxCountersPerReport {
			break
		}
		counters = append(counters, owed)
		sent = append(sent, prefix)
	}

	mode, entries := "unknown", 0
	if a.feed != nil {
		mode = "observe"
		if a.feed.Enforcing() {
			mode = "enforce"
		}
		entries = len(a.seen)
	}

	body, err := json.Marshal(map[string]any{
		"edge":     a.edge,
		"version":  version,
		"mode":     mode,
		"entries":  entries,
		"error":    a.lastError,
		"counters": counters,
	})
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.endpoint+"/caddy/blackhole/report", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+a.token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := a.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)

	if resp.StatusCode >= 300 {
		return fmt.Errorf("the report answered %d", resp.StatusCode)
	}
	for _, prefix := range sent {
		delete(a.pending, prefix)
	}
	return nil
}

// remove takes the whole table out, which also takes the rules out. Gone already is fine.
func (a *agent) remove(ctx context.Context) error {
	if _, ok := a.current(ctx); !ok {
		a.seen = map[netip.Prefix]element{}
		return nil
	}
	if _, err := a.nft.run(ctx, "", "delete", "table", "inet", tableName); err != nil {
		return fmt.Errorf("removing the table: %w", err)
	}
	a.seen = map[netip.Prefix]element{}
	return nil
}

// tick is one round: ask, apply, and remember what went wrong for the report.
func (a *agent) tick(ctx context.Context) {
	err := a.poll(ctx)
	switch {
	case errors.Is(err, errAddonGone):
		a.feed, a.etag, a.applied = nil, "", ""
		a.pending = map[netip.Prefix]counter{}
		if removeErr := a.remove(ctx); removeErr != nil {
			log.Print(removeErr)
		}
		a.lastError = ""
		return
	case err != nil:
		// The last list stands, and its timeouts keep running.
		log.Printf("feed: %v", err)
	}

	applyErr := a.apply(ctx)
	if applyErr != nil {
		log.Print(applyErr)
	}
	a.lastError = errorText(err, applyErr)
}

func errorText(errs ...error) string {
	var parts []string
	for _, err := range errs {
		if err != nil {
			parts = append(parts, err.Error())
		}
	}
	text := strings.Join(parts, "; ")
	if len(text) > 500 {
		return text[:500]
	}
	return text
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}

func main() {
	log.SetFlags(0)

	nft := nftBinary{path: env("NICETRY_BLACKHOLE_NFT", "nft")}
	command := "run"
	if len(os.Args) > 1 {
		command = os.Args[1]
	}

	switch command {
	case "flush":
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if _, err := nft.run(ctx, "", "list", "table", "inet", tableName); err != nil {
			fmt.Println("Nothing to flush: the table isn't there.")
			return
		}
		if _, err := nft.run(ctx, "", "delete", "table", "inet", tableName); err != nil {
			log.Fatal(err)
		}
		fmt.Println("Blackhole table removed. The agent puts it back on its next poll while it runs and the add-on is on.")
	case "version":
		fmt.Println(version)
	case "run":
		run(nft)
	default:
		log.Fatalf("usage: nicetry-blackhole [run|flush|version]")
	}
}

func run(nft runner) {
	endpoint := os.Getenv("NICETRY_APP_URL")
	token := os.Getenv("NICETRY_EDGE_TOKEN")
	if endpoint == "" || token == "" {
		log.Fatal("NICETRY_APP_URL and NICETRY_EDGE_TOKEN are required")
	}
	interval, err := time.ParseDuration(env("NICETRY_BLACKHOLE_INTERVAL", "30s"))
	if err != nil || interval < time.Second {
		log.Fatalf("NICETRY_BLACKHOLE_INTERVAL %q isn't a duration of a second or more", os.Getenv("NICETRY_BLACKHOLE_INTERVAL"))
	}
	host, _ := os.Hostname()
	ports := env("NICETRY_BLACKHOLE_PORTS", "80, 443")

	a := newAgent(endpoint, token, env("NICETRY_BLACKHOLE_EDGE", host), ports, nft)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	log.Printf("nicetry-blackhole %s polling %s every %s, ports %s", version, a.endpoint, interval, ports)

	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		a.tick(ctx)
		if a.feed != nil {
			if err := a.report(ctx); err != nil && ctx.Err() == nil {
				log.Printf("report: %v", err)
			}
		}
		select {
		case <-ctx.Done():
			// The table stays: the list keeps working through a restart, and its timeouts still run.
			return
		case <-ticker.C:
		}
	}
}
