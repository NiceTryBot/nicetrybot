package main

import (
	"encoding/json"
	"fmt"
	"net/netip"
	"slices"
	"strings"
	"time"
)

// maxEntries is the most addresses the agent will load, whatever the app sends. The app has its own,
// lower cap; this one stops a broken answer from filling the kernel.
const maxEntries = 200_000

// The widest ranges the agent accepts. The app refuses wider ones too; a /0 from a bug must not reach
// the kernel either way.
const (
	minBitsV4 = 16
	minBitsV6 = 32
)

// rearmAfter is how far a listed address's expiry can drift from what the kernel holds before the
// element is replaced, which resets its counter.
const rearmAfter = 2 * time.Minute

// alwaysProtected can never be blackholed, whatever the feed says: the box's own networks, load balancer
// health checks and the like. These are also accepted ahead of the drop rule.
var alwaysProtected = mustPrefixes(
	"0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12",
	"192.168.0.0/16", "224.0.0.0/4", "240.0.0.0/4",
	"::1/128", "fc00::/7", "fe80::/10", "ff00::/8",
)

func mustPrefixes(values ...string) []netip.Prefix {
	prefixes := make([]netip.Prefix, 0, len(values))
	for _, value := range values {
		prefixes = append(prefixes, netip.MustParsePrefix(value))
	}
	return prefixes
}

// Feed is what the app answers on /caddy/blackhole.
type Feed struct {
	Mode    string      `json:"mode"`
	Entries []FeedEntry `json:"entries"`
	Protect []string    `json:"protect"`
}

// FeedEntry is one address or range to blackhole, until a Unix time.
type FeedEntry struct {
	Address   string `json:"address"`
	ExpiresAt int64  `json:"expires_at"`
}

// Enforcing says whether listed addresses get dropped. Only "enforce" drops: anything else counts them.
func (f Feed) Enforcing() bool {
	return f.Mode == "enforce"
}

// desired is the feed as the kernel should hold it, after the agent's own checks.
type desired struct {
	enforcing bool
	entries   map[netip.Prefix]time.Time
	protect   []netip.Prefix
	skipped   []string
}

// parseAddress reads "203.0.113.9" or "198.51.100.0/24" as a network, in its canonical form.
func parseAddress(value string) (netip.Prefix, error) {
	value = strings.TrimSpace(value)
	if strings.Contains(value, "/") {
		prefix, err := netip.ParsePrefix(value)
		if err != nil {
			return netip.Prefix{}, err
		}
		if prefix.Addr().Is4In6() {
			bits := prefix.Bits() - 96
			if bits < 0 {
				return netip.Prefix{}, fmt.Errorf("%s is wider than IPv4", value)
			}
			return netip.PrefixFrom(prefix.Addr().Unmap(), bits).Masked(), nil
		}
		return prefix.Masked(), nil
	}
	addr, err := netip.ParseAddr(value)
	if err != nil {
		return netip.Prefix{}, err
	}
	addr = addr.WithZone("").Unmap()
	return netip.PrefixFrom(addr, addr.BitLen()), nil
}

func tooWide(prefix netip.Prefix) bool {
	if prefix.Addr().Is4() {
		return prefix.Bits() < minBitsV4
	}
	return prefix.Bits() < minBitsV6
}

func anyOverlap(prefix netip.Prefix, among []netip.Prefix) bool {
	for _, other := range among {
		if other.Overlaps(prefix) {
			return true
		}
	}
	return false
}

// plan applies the agent's own checks to a feed. An address that fails one is skipped and named, so one
// bad entry never takes the rest down: nftables refuses a whole transaction over one overlap.
func plan(feed Feed, now time.Time) desired {
	want := desired{enforcing: feed.Enforcing(), entries: map[netip.Prefix]time.Time{}}

	for _, value := range feed.Protect {
		prefix, err := parseAddress(value)
		if err != nil {
			want.skipped = append(want.skipped, "protect "+value)
			continue
		}
		want.protect = append(want.protect, prefix)
	}

	candidates := make([]netip.Prefix, 0, len(feed.Entries))
	expires := make(map[netip.Prefix]time.Time, len(feed.Entries))
	for _, entry := range feed.Entries {
		prefix, err := parseAddress(entry.Address)
		switch {
		case err != nil, tooWide(prefix), anyOverlap(prefix, alwaysProtected), anyOverlap(prefix, want.protect):
			want.skipped = append(want.skipped, entry.Address)
			continue
		}
		expiry := time.Unix(entry.ExpiresAt, 0)
		if !expiry.After(now.Add(time.Second)) {
			continue
		}
		if known, ok := expires[prefix]; !ok {
			candidates = append(candidates, prefix)
			expires[prefix] = expiry
		} else if expiry.After(known) {
			expires[prefix] = expiry
		}
	}

	// Widest first within each address, so a range swallows the addresses inside it.
	slices.SortFunc(candidates, func(a, b netip.Prefix) int {
		if order := a.Addr().Compare(b.Addr()); order != 0 {
			return order
		}
		return a.Bits() - b.Bits()
	})
	var last netip.Prefix
	for _, prefix := range candidates {
		if last.IsValid() && last.Overlaps(prefix) {
			continue
		}
		if len(want.entries) >= maxEntries {
			want.skipped = append(want.skipped, prefix.String())
			continue
		}
		want.entries[prefix] = expires[prefix]
		last = prefix
	}

	return want
}

// element is one entry the kernel holds now.
type element struct {
	expires time.Time
	packets uint64
	bytes   uint64
}

// parseSet reads the elements out of `nft -j list set`.
func parseSet(output []byte, now time.Time) (map[netip.Prefix]element, error) {
	var listing struct {
		Nftables []struct {
			Set *struct {
				Elem []struct {
					Elem struct {
						Val     json.RawMessage `json:"val"`
						Expires int64           `json:"expires"`
						Counter struct {
							Packets uint64 `json:"packets"`
							Bytes   uint64 `json:"bytes"`
						} `json:"counter"`
					} `json:"elem"`
				} `json:"elem"`
			} `json:"set"`
		} `json:"nftables"`
	}
	if err := json.Unmarshal(output, &listing); err != nil {
		return nil, fmt.Errorf("reading nft's listing: %w", err)
	}

	elements := map[netip.Prefix]element{}
	for _, item := range listing.Nftables {
		if item.Set == nil {
			continue
		}
		for _, entry := range item.Set.Elem {
			prefix, err := parseVal(entry.Elem.Val)
			if err != nil {
				return nil, err
			}
			elements[prefix] = element{
				expires: now.Add(time.Duration(entry.Elem.Expires) * time.Second),
				packets: entry.Elem.Counter.Packets,
				bytes:   entry.Elem.Counter.Bytes,
			}
		}
	}
	return elements, nil
}

func parseVal(raw json.RawMessage) (netip.Prefix, error) {
	var single string
	if err := json.Unmarshal(raw, &single); err == nil {
		return parseAddress(single)
	}
	var ranged struct {
		Prefix struct {
			Addr string `json:"addr"`
			Len  int    `json:"len"`
		} `json:"prefix"`
	}
	if err := json.Unmarshal(raw, &ranged); err != nil {
		return netip.Prefix{}, fmt.Errorf("reading nft's element %s: %w", raw, err)
	}
	return parseAddress(fmt.Sprintf("%s/%d", ranged.Prefix.Addr, ranged.Prefix.Len))
}

// script writes the one nft transaction that takes the kernel from current to want. It all applies or
// none of it does, and the chain is rebuilt inside it, so there's never a moment without the rules.
func script(want desired, current map[netip.Prefix]element, ports string) string {
	var out strings.Builder
	fmt.Fprintf(&out, "add table inet %s\n", tableName)
	for _, set := range []struct{ name, kind string }{{"blocked4", "ipv4_addr"}, {"blocked6", "ipv6_addr"}} {
		fmt.Fprintf(&out, "add set inet %s %s { type %s; flags interval,timeout; counter; }\n", tableName, set.name, set.kind)
	}
	for _, set := range []struct{ name, kind string }{{"protected4", "ipv4_addr"}, {"protected6", "ipv6_addr"}} {
		fmt.Fprintf(&out, "add set inet %s %s { type %s; flags interval; auto-merge; }\n", tableName, set.name, set.kind)
		fmt.Fprintf(&out, "flush set inet %s %s\n", tableName, set.name)
	}

	// raw runs before connection tracking, so a dropped client costs no conntrack entry either. Only the
	// web ports: SSH, and the answers to the edge's own connections, are never touched.
	fmt.Fprintf(&out, "add chain inet %s gate { type filter hook prerouting priority raw; policy accept; }\n", tableName)
	fmt.Fprintf(&out, "flush chain inet %s gate\n", tableName)
	fmt.Fprintf(&out, "add rule inet %s gate ip saddr @protected4 accept\n", tableName)
	fmt.Fprintf(&out, "add rule inet %s gate ip6 saddr @protected6 accept\n", tableName)
	verdict := ""
	if want.enforcing {
		verdict = " drop"
	}
	fmt.Fprintf(&out, "add rule inet %s gate meta l4proto { tcp, udp } th dport { %s } ip saddr @blocked4%s\n", tableName, ports, verdict)
	fmt.Fprintf(&out, "add rule inet %s gate meta l4proto { tcp, udp } th dport { %s } ip6 saddr @blocked6%s\n", tableName, ports, verdict)

	protected := append(slices.Clone(alwaysProtected), want.protect...)
	writeElements(&out, "add", "protected4", "protected6", protected, nil)

	var remove, add []netip.Prefix
	for prefix := range current {
		if _, keep := want.entries[prefix]; !keep {
			remove = append(remove, prefix)
		}
	}
	timeouts := make(map[netip.Prefix]time.Time, len(want.entries))
	for prefix, expiry := range want.entries {
		held, ok := current[prefix]
		if ok && absDuration(held.expires.Sub(expiry)) <= rearmAfter {
			continue
		}
		if ok {
			remove = append(remove, prefix)
		}
		add = append(add, prefix)
		timeouts[prefix] = expiry
	}
	writeElements(&out, "delete", "blocked4", "blocked6", remove, nil)
	writeElements(&out, "add", "blocked4", "blocked6", add, timeouts)

	return out.String()
}

func absDuration(d time.Duration) time.Duration {
	if d < 0 {
		return -d
	}
	return d
}

// writeElements adds or deletes elements in the IPv4 and IPv6 sets, in sorted batches.
func writeElements(out *strings.Builder, verb, set4, set6 string, prefixes []netip.Prefix, expiries map[netip.Prefix]time.Time) {
	slices.SortFunc(prefixes, func(a, b netip.Prefix) int { return a.Addr().Compare(b.Addr()) })

	var v4, v6 []string
	for _, prefix := range prefixes {
		text := formatPrefix(prefix)
		if expiry, ok := expiries[prefix]; ok {
			text += fmt.Sprintf(" timeout %ds", max(int64(time.Until(expiry).Seconds()), 1))
		}
		if prefix.Addr().Is4() {
			v4 = append(v4, text)
		} else {
			v6 = append(v6, text)
		}
	}
	writeBatches(out, verb, set4, v4)
	writeBatches(out, verb, set6, v6)
}

func writeBatches(out *strings.Builder, verb, set string, items []string) {
	for batch := range slices.Chunk(items, 500) {
		fmt.Fprintf(out, "%s element inet %s %s { %s }\n", verb, tableName, set, strings.Join(batch, ", "))
	}
}

// formatPrefix writes a lone address without its /32 or /128, as nft and the app do.
func formatPrefix(prefix netip.Prefix) string {
	if prefix.IsSingleIP() {
		return prefix.Addr().String()
	}
	return prefix.String()
}
