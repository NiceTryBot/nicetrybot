package nicetrycapture

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

const assetsTestSecret = "s3cr3t-only-cloudfront-knows"

func assetsTestFeed() string {
	sum := sha256.Sum256([]byte(assetsTestSecret))
	return `{"assets":{"domain":"nice-static.com","pull_host":"pull.nice-static.com","secret_sha256":"` + hex.EncodeToString(sum[:]) + `",
		"extensions":["png","css","js","mp4"],"skip_paths":["/wp-admin","/wp-login.php"],
		"sites":[
			{"key":"key0000000000001","site":"shop.example","hosts":["shop.example","www.shop.example","shop-example.preview.example"],"on":true},
			{"key":"offsiteoffsite00","site":"off.example","hosts":["off.example"],"on":false}
		]}}`
}

func assetsHandler(t *testing.T, raw string) *Assets {
	t.Helper()
	cfg, err := parseAssets([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	f := &assetsFeed{}
	f.current.Store(cfg)
	return &Assets{feed: f}
}

type assetsOutcome struct {
	w      *httptest.ResponseRecorder
	seen   *http.Request // what went on past the handler, nil when it answered itself
	vars   map[string]any
	fields *caddyhttp.ExtraLogFields
}

func serveAssets(t *testing.T, a *Assets, r *http.Request) assetsOutcome {
	t.Helper()
	vars := map[string]any{}
	fields := &caddyhttp.ExtraLogFields{}
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	ctx = context.WithValue(ctx, caddyhttp.ExtraLogFieldsCtxKey, fields)
	r = r.WithContext(ctx)

	out := assetsOutcome{w: httptest.NewRecorder(), vars: vars, fields: fields}
	err := a.ServeHTTP(out.w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		out.seen = r
		w.WriteHeader(http.StatusOK)
		return nil
	}))
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func pullRequest(method, target, secret string) *http.Request {
	r := httptest.NewRequest(method, "https://pull.nice-static.com"+target, nil)
	if secret != "" {
		r.Header.Set(assetsSecretHeader, secret)
	}
	r.Header.Set("Cookie", "session=abc")
	return r
}

func TestAPullBecomesARequestForTheFileOnTheKeysSite(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	out := serveAssets(t, a, pullRequest(http.MethodGet, "/key0000000000001/wp-content/uploads/2024/09/man.png?ver=2", assetsTestSecret))

	if out.seen == nil {
		t.Fatalf("pull was answered %d instead of going to the site", out.w.Code)
	}
	if out.seen.Host != "shop.example" || out.seen.URL.Path != "/wp-content/uploads/2024/09/man.png" || out.seen.URL.RawQuery != "ver=2" {
		t.Errorf("pull went on as %s %s?%s", out.seen.Host, out.seen.URL.Path, out.seen.URL.RawQuery)
	}
	if out.seen.RequestURI != "/wp-content/uploads/2024/09/man.png?ver=2" {
		t.Errorf("RequestURI %q", out.seen.RequestURI)
	}
	if out.seen.Header.Get(assetsSecretHeader) != "" || out.seen.Header.Get("Cookie") != "" {
		t.Error("the secret or a cookie would reach the origin")
	}
	if out.vars[nicetryAllowedVar] != true {
		t.Error("a pull isn't let past the tarpit, gym and verdict")
	}
	if out.vars[caddyhttp.LogSkipVar] != true {
		t.Error("a pull would be logged as a visitor")
	}
}

func TestAPulledFileCanBeUsedByAnyPageWhateverTheOriginSaid(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())
	r := pullRequest(http.MethodGet, "/key0000000000001/img/icons.png", assetsTestSecret)
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.VarsCtxKey, map[string]any{}))
	w := httptest.NewRecorder()

	err := a.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.Header().Set("Access-Control-Allow-Origin", "https://shop.example")
		w.Header().Set("Access-Control-Allow-Credentials", "true")
		_, err := w.Write([]byte("png"))
		return err
	}))
	if err != nil {
		t.Fatal(err)
	}

	if got := w.Header().Values("Access-Control-Allow-Origin"); len(got) != 1 || got[0] != "*" {
		t.Errorf("Access-Control-Allow-Origin %q", got)
	}
	if w.Header().Get("Access-Control-Allow-Credentials") != "" {
		t.Error("credentials allowed on a file anyone can fetch")
	}
	if w.Body.String() != "png" {
		t.Errorf("body %q", w.Body.String())
	}
}

// servePulledFile pulls target through the handler from an origin answering with contentType.
func servePulledFile(t *testing.T, target, contentType string) *httptest.ResponseRecorder {
	t.Helper()
	a := assetsHandler(t, assetsTestFeed())
	r := pullRequest(http.MethodGet, target, assetsTestSecret)
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.VarsCtxKey, map[string]any{}))
	w := httptest.NewRecorder()

	err := a.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.Header().Set("Content-Type", contentType)
		_, err := w.Write([]byte("file"))
		return err
	}))
	if err != nil {
		t.Fatal(err)
	}
	return w
}

func TestAPulledSVGCantRunScriptOnTheCDNsHostname(t *testing.T) {
	feed := strings.Replace(assetsTestFeed(), `"extensions":["png",`, `"extensions":["svg","png",`, 1)
	a := assetsHandler(t, feed)
	r := pullRequest(http.MethodGet, "/key0000000000001/img/Logo.SVG", assetsTestSecret)
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.VarsCtxKey, map[string]any{}))
	w := httptest.NewRecorder()

	err := a.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.Header().Set("Content-Type", "image/svg+xml")
		w.Header().Set("Content-Security-Policy", "default-src 'self'")
		_, err := w.Write([]byte("<svg/>"))
		return err
	}))
	if err != nil {
		t.Fatal(err)
	}

	if got := w.Header().Values("Content-Security-Policy"); len(got) != 2 || got[1] != "sandbox" {
		t.Errorf("Content-Security-Policy %q", got)
	}
	if w.Header().Get("Access-Control-Allow-Origin") != "*" {
		t.Error("the SVG lost its CORS header")
	}
}

func TestAPulledSVGByItsTypeIsSandboxedAndOtherFilesAreNot(t *testing.T) {
	if got := servePulledFile(t, "/key0000000000001/img/logo.png", "image/svg+xml; charset=utf-8").Header().Get("Content-Security-Policy"); got != "sandbox" {
		t.Errorf("an SVG answered for a .png: Content-Security-Policy %q", got)
	}
	for target, contentType := range map[string]string{
		"/key0000000000001/img/logo.png": "image/png",
		"/key0000000000001/css/site.css": "text/css",
		"/key0000000000001/js/app.js":    "text/javascript",
	} {
		if got := servePulledFile(t, target, contentType).Header().Values("Content-Security-Policy"); len(got) != 0 {
			t.Errorf("%s: Content-Security-Policy %q", target, got)
		}
	}
}

func TestAPullUnderAPullAliasIsServedLikeOneToThePullHost(t *testing.T) {
	feed := strings.Replace(assetsTestFeed(), `"pull_host":"pull.nice-static.com",`, `"pull_host":"pull.nice-static.com","pull_aliases":["CDN.nice-static.com"],`, 1)
	a := assetsHandler(t, feed)
	alias := func(target, secret string) *http.Request {
		r := pullRequest(http.MethodGet, target, secret)
		r.Host = "cdn.nice-static.com"
		return r
	}

	out := serveAssets(t, a, alias("/key0000000000001/img/logo.png?v=2", assetsTestSecret))
	if out.seen == nil {
		t.Fatalf("pull under the alias was answered %d instead of going to the site", out.w.Code)
	}
	if out.seen.Host != "shop.example" || out.seen.URL.Path != "/img/logo.png" || out.seen.URL.RawQuery != "v=2" {
		t.Errorf("pull went on as %s %s?%s", out.seen.Host, out.seen.URL.Path, out.seen.URL.RawQuery)
	}
	if out.seen.Header.Get(assetsSecretHeader) != "" || out.seen.Header.Get("Cookie") != "" {
		t.Error("the secret or a cookie would reach the origin")
	}
	if out.vars[nicetryAllowedVar] != true || out.vars[caddyhttp.LogSkipVar] != true {
		t.Error("a pull under the alias isn't let past the gym or would be logged")
	}
	if out.w.Header().Get("Access-Control-Allow-Origin") != "*" {
		t.Error("a pull under the alias lost its CORS header")
	}

	for name, r := range map[string]*http.Request{
		"no secret":     alias("/key0000000000001/img/logo.png", ""),
		"wrong secret":  alias("/key0000000000001/img/logo.png", "guess"),
		"a page":        alias("/key0000000000001/index.php", assetsTestSecret),
		"the bare host": alias("/", ""),
	} {
		out := serveAssets(t, a, r)
		if out.seen != nil || out.w.Code != http.StatusNotFound {
			t.Errorf("%s: got %d, went on: %v", name, out.w.Code, out.seen != nil)
		}
	}
}

func TestWithoutPullAliasesTheCDNsOwnHostnameIsNoPullHost(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())
	r := pullRequest(http.MethodGet, "/key0000000000001/img/logo.png", assetsTestSecret)
	r.Host = "cdn.nice-static.com"

	out := serveAssets(t, a, r)

	if out.seen == nil || out.seen.Host != "cdn.nice-static.com" || out.seen.URL.Path != "/key0000000000001/img/logo.png" {
		t.Errorf("a request to a host that isn't the pull host was treated as a pull (%d)", out.w.Code)
	}
	if out.seen != nil && out.seen.Header.Get(assetsSecretHeader) != "" {
		t.Error("the secret went on")
	}
}

func TestASiteThatIsOffCanStillBePulledSoCachedPagesKeepTheirFiles(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	out := serveAssets(t, a, pullRequest(http.MethodHead, "/offsiteoffsite00/logo.png", assetsTestSecret))

	if out.seen == nil || out.seen.Host != "off.example" {
		t.Fatalf("pull for a site that's off answered %d", out.w.Code)
	}
}

func TestThePullHostServesNothingButListedFilesToTheCDN(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	for name, r := range map[string]*http.Request{
		"no secret":          pullRequest(http.MethodGet, "/key0000000000001/logo.png", ""),
		"wrong secret":       pullRequest(http.MethodGet, "/key0000000000001/logo.png", "guess"),
		"unknown key":        pullRequest(http.MethodGet, "/aaaaaaaaaaaaaaaa/logo.png", assetsTestSecret),
		"a page":             pullRequest(http.MethodGet, "/key0000000000001/index.php", assetsTestSecret),
		"no extension":       pullRequest(http.MethodGet, "/key0000000000001/wp-json/wp/v2/users", assetsTestSecret),
		"a post":             pullRequest(http.MethodPost, "/key0000000000001/logo.png", assetsTestSecret),
		"a skipped path":     pullRequest(http.MethodGet, "/key0000000000001/wp-admin/css/admin.css", assetsTestSecret),
		"the gym":            pullRequest(http.MethodGet, "/key0000000000001/_q/gym.js", assetsTestSecret),
		"a service worker":   pullRequest(http.MethodGet, "/key0000000000001/sw.js", assetsTestSecret),
		"the bare pull host": pullRequest(http.MethodGet, "/", assetsTestSecret),
	} {
		out := serveAssets(t, a, r)
		if out.seen != nil || out.w.Code != http.StatusNotFound {
			t.Errorf("%s: got %d, went on: %v", name, out.w.Code, out.seen != nil)
		}
	}
}

func TestAVisitorAskingASiteThatIsOnForAFileIsSentToTheCDN(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	for _, host := range []string{"shop.example", "WWW.Shop.example:443", "shop-example.preview.example"} {
		r := httptest.NewRequest(http.MethodGet, "https://"+host+"/wp-content/themes/a%20b/style.css?ver=6.4", nil)
		out := serveAssets(t, a, r)

		if out.w.Code != http.StatusFound || out.seen != nil {
			t.Fatalf("%s: got %d", host, out.w.Code)
		}
		if got := out.w.Header().Get("Location"); got != "https://key0000000000001.nice-static.com/wp-content/themes/a%20b/style.css?ver=6.4" {
			t.Errorf("%s: Location %q", host, got)
		}
		if out.vars["bot_tag"] != assetsBotTag {
			t.Errorf("%s: not tagged in the log", host)
		}
	}
}

func TestAStragglerGoesToTheSitesCDNBaseURLInPathStyle(t *testing.T) {
	feed := strings.Replace(assetsTestFeed(), `"site":"shop.example",`, `"site":"shop.example","to":"https://CDN.nice-static.com/key0000000000001/",`, 1)
	feed = strings.Replace(feed, `"domain":"nice-static.com",`, `"domain":"nice-static.com","style":"path",`, 1)
	a := assetsHandler(t, feed)

	out := serveAssets(t, a, httptest.NewRequest(http.MethodGet, "https://www.shop.example/wp-content/themes/a%20b/style.css?ver=6.4", nil))

	if out.w.Code != http.StatusFound || out.seen != nil {
		t.Fatalf("got %d", out.w.Code)
	}
	if got := out.w.Header().Get("Location"); got != "https://cdn.nice-static.com/key0000000000001/wp-content/themes/a%20b/style.css?ver=6.4" {
		t.Errorf("Location %q", got)
	}
}

func TestAStragglerGoesToKeyDotDomainWithoutAUsableBaseURL(t *testing.T) {
	for name, site := range map[string]string{
		"no to":           `"site":"shop.example",`,
		"host style":      `"site":"shop.example","to":"https://key0000000000001.nice-static.com",`,
		"not https":       `"site":"shop.example","to":"http://cdn.nice-static.com/key0000000000001",`,
		"with a query":    `"site":"shop.example","to":"https://cdn.nice-static.com/key0000000000001?x=1",`,
		"with a fragment": `"site":"shop.example","to":"https://cdn.nice-static.com/key0000000000001#x",`,
		"a quote":         `"site":"shop.example","to":"https://cdn.nice-static.com/key0000000000001'",`,
		"no host":         `"site":"shop.example","to":"https:///key0000000000001",`,
	} {
		a := assetsHandler(t, strings.Replace(assetsTestFeed(), `"site":"shop.example",`, site, 1))

		out := serveAssets(t, a, httptest.NewRequest(http.MethodGet, "https://shop.example/img/a.png?v=2", nil))

		if got := out.w.Header().Get("Location"); out.w.Code != http.StatusFound || got != "https://key0000000000001.nice-static.com/img/a.png?v=2" {
			t.Errorf("%s: got %d, Location %q", name, out.w.Code, got)
		}
	}
}

func TestEverythingElseGoesOnToTheSite(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	for name, r := range map[string]*http.Request{
		"a page":            httptest.NewRequest(http.MethodGet, "https://shop.example/products/", nil),
		"a post":            httptest.NewRequest(http.MethodPost, "https://shop.example/upload.png", nil),
		"a site that's off": httptest.NewRequest(http.MethodGet, "https://off.example/logo.png", nil),
		"another site":      httptest.NewRequest(http.MethodGet, "https://blog.example/logo.png", nil),
		"the gym":           httptest.NewRequest(http.MethodGet, "https://shop.example/_q/gym.js", nil),
		"a skipped path":    httptest.NewRequest(http.MethodGet, "https://shop.example/wp-admin/css/admin.css", nil),
		"a service worker":  httptest.NewRequest(http.MethodGet, "https://shop.example/firebase-messaging-sw.js", nil),
		"a web worker":      httptest.NewRequest(http.MethodGet, "https://shop.example/js/OneSignalSDKWorker.js", nil),
		"another file type": httptest.NewRequest(http.MethodGet, "https://shop.example/brochure.pdf", nil),
	} {
		if out := serveAssets(t, a, r); out.seen == nil {
			t.Errorf("%s: answered %d instead of going on", name, out.w.Code)
		}
	}
}

func TestAVisitorsOwnSecretHeaderNeverReachesTheOrigin(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())
	r := httptest.NewRequest(http.MethodGet, "https://shop.example/", nil)
	r.Header.Set(assetsSecretHeader, assetsTestSecret)

	if out := serveAssets(t, a, r); out.seen.Header.Get(assetsSecretHeader) != "" {
		t.Error("the visitor's X-NiceTry-Assets went on")
	}
}

func TestAnAnswerWithoutAnAssetDomainMeansNoCDN(t *testing.T) {
	a := assetsHandler(t, `{"assets":{"domain":"","pull_host":"","secret_sha256":"","extensions":[],"skip_paths":[],"sites":[]}}`)

	if out := serveAssets(t, a, httptest.NewRequest(http.MethodGet, "https://shop.example/logo.png", nil)); out.seen == nil {
		t.Error("a file was sent to a CDN there isn't")
	}
	if _, err := parseAssets([]byte(`{"sites":[]}`)); err == nil {
		t.Error("an answer without assets was taken as a list")
	}
}

const assetsRewriteFeed = `{"rewrites":[{"id":1,"hosts":["shop.example","www.shop.example"],"find":"","replace":"","ignore_case":false,"in":"html",
	"assets":{"to":"https://key0000000000001.nice-static.com","extensions":["png","css","js","woff2"],"skip_paths":["/wp-admin"]}}]}`

// serveAssetRewrite sends a GET for target through the rewrite handler to an origin answering with body.
func serveAssetRewrite(t *testing.T, target, contentType, body string) string {
	t.Helper()
	return serveAssetRewriteFrom(t, assetsRewriteFeed, target, contentType, body)
}

// serveAssetRewriteFrom is serveAssetRewrite with the rewrites from feed.
func serveAssetRewriteFrom(t *testing.T, feed, target, contentType, body string) string {
	t.Helper()
	rw := rewriteHandler(t, feed, rewriteMaxBody)
	r := httptest.NewRequest(http.MethodGet, target, nil)
	w := httptest.NewRecorder()
	err := rw.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		w.Header().Set("Content-Type", contentType)
		w.Header().Set("Content-Length", strconv.Itoa(len(body)))
		_, err := w.Write([]byte(body))
		return err
	}))
	if err != nil {
		t.Fatal(err)
	}
	return w.Body.String()
}

func TestAPagesFileURLsPointAtTheCDN(t *testing.T) {
	page := strings.Join([]string{
		`<link rel="stylesheet" href="https://shop.example/wp-content/themes/n/style.css?ver=6.4">`,
		`<script src="//www.shop.example/wp-includes/js/jquery.js"></script>`,
		`<img src="/wp-content/uploads/2024/09/man.png" srcset="/a.png 1x, /b.png 2x">`,
		`<div style="background:url('/img/hero.png')"></div><img src=/bare.png>`,
		`<script>var cfg = {"logo":"https:\/\/shop.example\/wp-content\/logo.png"};</script>`,
		`<a href="/products/">Products</a> <a href="https://shop.example/brochure.pdf">PDF</a>`,
		`<img src="https://blog.example/other.png"> <script>navigator.serviceWorker.register("/sw.js")</script>`,
		`<a href="//cdn.example//shop.example/x.png">`,
	}, "\n")

	got := serveAssetRewrite(t, "https://shop.example/", "text/html; charset=utf-8", page)

	want := strings.Join([]string{
		`<link rel="stylesheet" href="https://key0000000000001.nice-static.com/wp-content/themes/n/style.css?ver=6.4">`,
		`<script src="https://key0000000000001.nice-static.com/wp-includes/js/jquery.js"></script>`,
		`<img src="https://key0000000000001.nice-static.com/wp-content/uploads/2024/09/man.png" srcset="https://key0000000000001.nice-static.com/a.png 1x, https://key0000000000001.nice-static.com/b.png 2x">`,
		`<div style="background:url('https://key0000000000001.nice-static.com/img/hero.png')"></div><img src=https://key0000000000001.nice-static.com/bare.png>`,
		`<script>var cfg = {"logo":"https:\/\/key0000000000001.nice-static.com\/wp-content\/logo.png"};</script>`,
		`<a href="/products/">Products</a> <a href="https://shop.example/brochure.pdf">PDF</a>`,
		`<img src="https://blog.example/other.png"> <script>navigator.serviceWorker.register("/sw.js")</script>`,
		`<a href="//cdn.example//shop.example/x.png">`,
	}, "\n")
	if got != want {
		t.Errorf("page came out as\n%s\nwant\n%s", got, want)
	}
}

func TestAPagesFileURLsPointAtTheSitesFolderOnAPathStyleCDN(t *testing.T) {
	feed := strings.Replace(assetsRewriteFeed, `"to":"https://key0000000000001.nice-static.com"`, `"to":"https://cdn.nice-static.com/key0000000000001/"`, 1)
	page := strings.Join([]string{
		`<link rel="stylesheet" href="https://shop.example/wp-content/themes/n/style.css?ver=6.4">`,
		`<img src="/img/a.png" srcset="/a.png 1x, /b.png 2x"><div style="background:url('/img/hero.png')"></div>`,
		`<script>var cfg = {"logo":"https:\/\/shop.example\/wp-content\/logo.png"};</script>`,
		`<a href="/products/">Products</a>`,
	}, "\n")

	got := serveAssetRewriteFrom(t, feed, "https://shop.example/", "text/html; charset=utf-8", page)

	want := strings.Join([]string{
		`<link rel="stylesheet" href="https://cdn.nice-static.com/key0000000000001/wp-content/themes/n/style.css?ver=6.4">`,
		`<img src="https://cdn.nice-static.com/key0000000000001/img/a.png" srcset="https://cdn.nice-static.com/key0000000000001/a.png 1x, https://cdn.nice-static.com/key0000000000001/b.png 2x"><div style="background:url('https://cdn.nice-static.com/key0000000000001/img/hero.png')"></div>`,
		`<script>var cfg = {"logo":"https:\/\/cdn.nice-static.com\/key0000000000001\/wp-content\/logo.png"};</script>`,
		`<a href="/products/">Products</a>`,
	}, "\n")
	if got != want {
		t.Errorf("page came out as\n%s\nwant\n%s", got, want)
	}
}

func TestStylesheetsAndScriptsComeOutByteForByteSoTheirIntegrityHashesHold(t *testing.T) {
	css := `@font-face{src:url(/fonts/a.woff2)} .hero{background:url("https://shop.example/img/b.png")}`
	if got := serveAssetRewrite(t, "https://shop.example/style.css", "text/css", css); got != css {
		t.Errorf("stylesheet came out as %s", got)
	}

	js := `load("/img/b.png")`
	if got := serveAssetRewrite(t, "https://shop.example/app.js", "application/javascript", js); got != js {
		t.Errorf("script came out as %s", got)
	}
}

func TestATagPinnedWithSubresourceIntegrityKeepsItsURL(t *testing.T) {
	page := `<script src="/app.js" integrity="sha384-abc"></script><script src="/other.js"></script>` + "\n" +
		`<LINK rel="stylesheet" href="https://shop.example/s.css"
			Integrity="sha384-def"><link rel="stylesheet" href="/t.css">`

	got := serveAssetRewrite(t, "https://shop.example/", "text/html", page)

	want := `<script src="/app.js" integrity="sha384-abc"></script><script src="https://key0000000000001.nice-static.com/other.js"></script>` + "\n" +
		`<LINK rel="stylesheet" href="https://shop.example/s.css"
			Integrity="sha384-def"><link rel="stylesheet" href="https://key0000000000001.nice-static.com/t.css">`
	if got != want {
		t.Errorf("page came out as\n%s\nwant\n%s", got, want)
	}
}

func TestAClassicScriptOrStylesheetIsServedHereSoAnIntegrityCheckCanStillPass(t *testing.T) {
	a := assetsHandler(t, assetsTestFeed())

	for _, c := range []struct {
		path, dest, mode string
		redirected       bool
	}{
		{"/app.js", "script", "no-cors", false},
		{"/style.css", "style", "no-cors", false},
		{"/module.js", "script", "cors", true},
		{"/logo.png", "image", "no-cors", true},
		{"/app.js", "", "", true},
	} {
		r := httptest.NewRequest(http.MethodGet, "https://shop.example"+c.path, nil)
		if c.dest != "" {
			r.Header.Set("Sec-Fetch-Dest", c.dest)
			r.Header.Set("Sec-Fetch-Mode", c.mode)
		}
		if out := serveAssets(t, a, r); (out.w.Code == http.StatusFound) != c.redirected {
			t.Errorf("%s (%s, %s): got %d", c.path, c.dest, c.mode, out.w.Code)
		}
	}
}

func TestAPageUnderASkipPathIsLeftAlone(t *testing.T) {
	page := `<img src="/wp-content/uploads/man.png">`
	if got := serveAssetRewrite(t, "https://shop.example/wp-admin/post.php?post=1", "text/html", page); got != page {
		t.Errorf("editor page came out as %s", got)
	}
}

func TestAnAssetRewriteWithoutAPlainHTTPSTargetIsSkipped(t *testing.T) {
	for _, to := range []string{
		"javascript:alert(1)",
		"https://cdn.nice-static.com/key0000000000001?v=1",
		"https://cdn.nice-static.com/key0000000000001#top",
		`https://cdn.nice-static.com/key0000000000001'onerror=alert(1)`,
	} {
		rewrites, err := parseRewrites([]byte(`{"rewrites":[{"id":1,"hosts":["shop.example"],"find":"","in":"html","assets":{"to":"` + to + `","extensions":["png"],"skip_paths":[]}}]}`))
		if err != nil {
			t.Fatal(err)
		}
		if len(rewrites["shop.example"]) != 0 {
			t.Errorf("a rewrite to %s was kept", to)
		}
	}
}
