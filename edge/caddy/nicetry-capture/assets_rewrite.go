package nicetrycapture

import (
	"bytes"
	"net/url"
	"path"
	"regexp"
	"strings"
)

// assetRewrite moves a site's file URLs to its CDN base URL in its pages (nicetry_rewrite, for the
// Offload Assets add-on). Only files with the listed extensions move, never one under a skip path, never
// a script a browser insists comes from the page's own origin (a service worker or a web worker), and
// never one a tag pins with Subresource Integrity: moved, a same-origin <script integrity> without
// crossorigin becomes a cross-origin no-cors fetch the browser can't check, and refuses.
type assetRewrite struct {
	to         []byte // https://<key>.<asset domain> (host style) or https://cdn.<asset domain>/<key> (path style)
	toEscaped  []byte // the same, as JSON writes it: https:\/\/<key>.<asset domain>, https:\/\/cdn.<asset domain>\/<key>
	hosts      map[string]bool
	extensions map[string]bool
	skipPaths  []string
}

var (
	// An absolute or protocol-relative URL, plainly or JSON-escaped: its host, and its path with any
	// query (up to a quote, a space, a bracket, a comma or a backslash that isn't part of \/).
	assetAbsoluteURL = regexp.MustCompile(`(?i)(?:https?:)?(//|\\/\\/)([a-z0-9][a-z0-9.-]*)(?::443)?((?:/|\\/)(?:\\/|[^\s"'<>()\\,])*)`)

	// A root-relative URL just after what starts one in HTML and CSS: a quote, (, = or a srcset comma.
	assetRootRelativeURL = regexp.MustCompile(`(["'(=]|,[ \t]*)(/[^/\s"'<>()\\,][^\s"'<>()\\,]*)`)

	// A <script> or <link> tag with an integrity hash, which is left exactly as it is.
	assetIntegrityTag = regexp.MustCompile(`(?is)<(?:script|link)\b[^>]*\bintegrity\s*=[^>]*>`)
)

// assetSafePath is what a CDN base URL's path may hold: it's written into pages as it is, so nothing that
// could end a quoted attribute, a url() or a srcset entry, or start a root-relative URL of its own.
var assetSafePath = regexp.MustCompile(`^[A-Za-z0-9._~%/-]*$`)

// assetBaseURL turns a feed's `to` into the base URL a file's path is appended to: https://<host> with
// the host lowercased, then any path, escaped and without a trailing slash. A `to` that isn't https, has
// no host, carries a query or a fragment, or whose path holds anything but plain URL characters, is
// refused.
func assetBaseURL(to string) (string, bool) {
	target, err := url.Parse(to)
	if err != nil || target.Scheme != "https" || target.Host == "" || strings.ContainsAny(to, "?#") {
		return "", false
	}
	basePath := strings.TrimRight(target.EscapedPath(), "/")
	if !assetSafePath.MatchString(basePath) {
		return "", false
	}
	return "https://" + strings.ToLower(target.Host) + basePath, true
}

// newAssetRewrite builds an asset rewrite from the feed, or nil when it names nowhere to move URLs to.
func newAssetRewrite(hosts []string, to string, extensions, skipPaths []string) *assetRewrite {
	base, ok := assetBaseURL(to)
	if !ok || len(extensions) == 0 {
		return nil
	}
	a := &assetRewrite{
		to:         []byte(base),
		toEscaped:  []byte(strings.ReplaceAll(base, "/", `\/`)),
		hosts:      map[string]bool{},
		extensions: map[string]bool{},
		skipPaths:  skipPaths,
	}
	for _, host := range hosts {
		a.hosts[strings.ToLower(host)] = true
	}
	for _, ext := range extensions {
		a.extensions[strings.ToLower(ext)] = true
	}
	return a
}

// apply rewrites the URLs in a page, around any tags pinned with Subresource Integrity.
func (a *assetRewrite) apply(body []byte) []byte {
	tags := assetIntegrityTag.FindAllIndex(body, -1)
	if len(tags) == 0 {
		return a.rewriteURLs(body)
	}
	out := make([]byte, 0, len(body)+len(body)/8)
	last := 0
	for _, tag := range tags {
		out = append(out, a.rewriteURLs(body[last:tag[0]])...)
		out = append(out, body[tag[0]:tag[1]]...)
		last = tag[1]
	}
	return append(out, a.rewriteURLs(body[last:])...)
}

// rewriteURLs moves the file URLs in a stretch of a page.
func (a *assetRewrite) rewriteURLs(body []byte) []byte {
	body = assetAbsoluteURL.ReplaceAllFunc(body, func(match []byte) []byte {
		parts := assetAbsoluteURL.FindSubmatch(match)
		escaped := bytes.Equal(parts[1], []byte(`\/\/`))
		if !a.hosts[strings.ToLower(string(parts[2]))] || !a.moves(strings.ReplaceAll(string(parts[3]), `\/`, "/")) {
			return match
		}
		if escaped {
			return append(append([]byte{}, a.toEscaped...), parts[3]...)
		}
		return append(append([]byte{}, a.to...), parts[3]...)
	})
	return assetRootRelativeURL.ReplaceAllFunc(body, func(match []byte) []byte {
		parts := assetRootRelativeURL.FindSubmatch(match)
		if !a.moves(string(parts[2])) {
			return match
		}
		return append(append(append([]byte{}, parts[1]...), a.to...), parts[2]...)
	})
}

// moves reports whether a URL's path (with any query or fragment) names a file that goes to the CDN.
func (a *assetRewrite) moves(rawPath string) bool {
	p, _, _ := strings.Cut(rawPath, "?")
	p, _, _ = strings.Cut(p, "#")
	ext := strings.ToLower(strings.TrimPrefix(path.Ext(p), "."))
	return ext != "" && a.extensions[ext] && !a.skipped(p) && !assetMustStayHome(p)
}

// skipped reports whether a path is under one of the paths left alone.
func (a *assetRewrite) skipped(p string) bool {
	return pathUnder(p, a.skipPaths)
}

// pathUnder reports whether a path is one of the prefixes or under it (/wp-admin, /wp-admin/…, but not
// /wp-administrator).
func pathUnder(p string, prefixes []string) bool {
	for _, prefix := range prefixes {
		if strings.HasPrefix(p, prefix) && (len(p) == len(prefix) || strings.HasSuffix(prefix, "/") || p[len(prefix)] == '/') {
			return true
		}
	}
	return false
}

// assetMustStayHome reports whether a script has to come from the page's own origin: browsers refuse a
// service worker or a web worker from anywhere else (sw.js, service-worker.js, firebase-messaging-sw.js,
// OneSignalSDKWorker.js).
func assetMustStayHome(p string) bool {
	base := strings.ToLower(path.Base(p))
	if ext := path.Ext(base); ext != ".js" && ext != ".mjs" {
		return false
	}
	name := strings.TrimSuffix(strings.TrimSuffix(base, ".js"), ".mjs")
	return strings.Contains(name, "worker") || name == "sw" || strings.HasSuffix(name, "-sw") || strings.HasSuffix(name, "_sw") || strings.HasSuffix(name, ".sw")
}
