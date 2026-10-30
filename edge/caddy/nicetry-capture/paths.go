package nicetrycapture

import (
	"net/http"
	"net/url"
	"path"
	"strings"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// judgedPathsVar is where a request keeps its judged paths, so the handlers that need them work them
// out once.
const judgedPathsVar = "nicetry_judged_paths"

// judgedPaths lists the paths a request is judged by: captures, gym triggers, path rules and tarpits
// match it if any of them match. Origins don't see the path the way it was sent: Caddy's routing and
// most servers clean it (/_q/../login is /login), and a PHP front controller routes what follows its
// script (/index.php/login is /login). So the list is the cleaned path first, then the path without a
// leading *.php segment, then the path as sent when that differs (so nothing that matched before stops
// matching). Most requests have just the one.
func judgedPaths(r *http.Request) []string {
	if paths, ok := caddyhttp.GetVar(r.Context(), judgedPathsVar).([]string); ok {
		return paths
	}
	paths := pathsFor(r.URL.Path)
	caddyhttp.SetVar(r.Context(), judgedPathsVar, paths)
	return paths
}

func pathsFor(sent string) []string {
	// The usual request: nothing to clean and no PHP script in front.
	if strings.HasPrefix(sent, "/") && !strings.Contains(sent, "//") && !strings.Contains(sent, "/.") && !strings.Contains(strings.ToLower(sent), ".php/") {
		return []string{sent}
	}

	cleaned := cleanPath(sent)
	paths := []string{cleaned}
	if rest, ok := afterFrontController(cleaned); ok && rest != cleaned {
		paths = append(paths, rest)
	}
	if sent != cleaned && sent != "" {
		paths = append(paths, sent)
	}
	return paths
}

// cleanPath is the path as path.Clean leaves it, always starting with "/" and keeping a trailing
// slash, since /admin/ and /admin are different pages to globs like "/admin/*".
func cleanPath(p string) string {
	cleaned := path.Clean("/" + p)
	if strings.HasSuffix(p, "/") && cleaned != "/" {
		cleaned += "/"
	}
	return cleaned
}

// afterFrontController drops a first segment naming a PHP script (/index.php/login → /login), which
// PHP's front controllers route as the path that follows.
func afterFrontController(cleaned string) (string, bool) {
	first, rest, found := strings.Cut(cleaned[1:], "/")
	if !found || !strings.HasSuffix(strings.ToLower(first), ".php") {
		return "", false
	}
	return "/" + rest, true
}

// cleanedPath is the first judged path: the request's path, cleaned.
func cleanedPath(r *http.Request) string {
	return judgedPaths(r)[0]
}

// hasDotSegments reports whether a (decoded) path climbs or stands still with "." or ".." segments.
// Browsers resolve those before sending (%2e%2e too), so only scanners and bypass attempts send them.
// Empty segments ("//") are left alone: some sites' links really have them.
func hasDotSegments(p string) bool {
	for segment := range strings.SplitSeq(p, "/") {
		if segment == "." || segment == ".." {
			return true
		}
	}
	return false
}

// uriFor is the request's URI with path in place of the one it was sent with.
func uriFor(r *http.Request, p string) string {
	uri := (&url.URL{Path: p}).EscapedPath()
	if r.URL.RawQuery != "" {
		uri += "?" + r.URL.RawQuery
	}
	return uri
}
