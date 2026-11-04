package nicetrycapture

import (
	"encoding/json"
	"net"
	"net/http"
	"regexp"
	"slices"
	"strconv"
	"strings"
)

// A team's flag patterns (Flag Watch) ride along with the captures as response_watch: regular expressions
// looked for in every response a capture records, body and headers, on the pattern's hosts. What matches
// is logged as flags, [{"id": <pattern>, "value": <the match>}], beside the captured response. An older
// app without the list sends none; an older edge ignores it.

// maxFlagMatches is the most matches logged for one response; maxFlagLength cuts each one.
const (
	maxFlagMatches = 20
	maxFlagLength  = 500
)

// responseWatch is one flag pattern on a set of hostnames.
type responseWatch struct {
	ID    int
	Hosts map[string]bool
	Re    *regexp.Regexp
}

// flagMatch is one flag found in a response, as logged.
type flagMatch struct {
	ID    int    `json:"id"`
	Value string `json:"value"`
}

// parseResponseWatch reads the response_watch list. A pattern that doesn't compile is left out.
func parseResponseWatch(raw json.RawMessage) []responseWatch {
	if len(raw) == 0 {
		return nil
	}

	var entries []struct {
		ID      int      `json:"id"`
		Hosts   []string `json:"hosts"`
		Pattern string   `json:"pattern"`
	}
	if json.Unmarshal(raw, &entries) != nil {
		return nil
	}

	watches := make([]responseWatch, 0, len(entries))
	for _, e := range entries {
		re, err := regexp.Compile(e.Pattern)
		if err != nil {
			skippedPattern("response_watch pattern", strconv.Itoa(e.ID), err)
			continue
		}
		hosts := map[string]bool{}
		for _, h := range e.Hosts {
			hosts[strings.ToLower(h)] = true
		}
		watches = append(watches, responseWatch{ID: e.ID, Hosts: hosts, Re: re})
	}
	return watches
}

// findFlags looks for the patterns on the request's host in a response's headers and body: every
// distinct match, headers first, up to maxFlagMatches.
func findFlags(watches []responseWatch, r *http.Request, header http.Header, body []byte) []flagMatch {
	if len(watches) == 0 {
		return nil
	}

	host := strings.ToLower(r.Host)
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}

	var headers strings.Builder
	names := make([]string, 0, len(header))
	for name := range header {
		names = append(names, name)
	}
	slices.Sort(names)
	for _, name := range names {
		for _, value := range header[name] {
			headers.WriteString(name + ": " + value + "\n")
		}
	}

	var found []flagMatch
	seen := map[string]bool{}
	for _, w := range watches {
		if !w.Hosts[host] {
			continue
		}
		for _, text := range [][]byte{[]byte(headers.String()), body} {
			for _, m := range w.Re.FindAll(text, maxFlagMatches) {
				value := truncate(string(m), maxFlagLength)
				key := strconv.Itoa(w.ID) + "\x00" + value
				if value == "" || seen[key] {
					continue
				}
				seen[key] = true
				found = append(found, flagMatch{ID: w.ID, Value: value})
				if len(found) == maxFlagMatches {
					return found
				}
			}
		}
	}
	return found
}
