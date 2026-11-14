package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func rewriteHandler(t *testing.T, raw string, maxBody int) *Rewrite {
	t.Helper()
	rewrites, err := parseRewrites([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	m := &rewriteMap{}
	m.current.Store(&rewrites)
	return &Rewrite{rewrites: m, MaxBody: maxBody}
}

type rewriteOrigin struct {
	contentType, encoding, body string
	chunks                      []string
	seen                        *http.Request
}

// serveRewrite sends a GET for host through the handler to an origin answering as told, and returns
// what the visitor got.
func serveRewrite(t *testing.T, rw *Rewrite, host string, o *rewriteOrigin) *httptest.ResponseRecorder {
	t.Helper()
	r := httptest.NewRequest(http.MethodGet, "https://"+host+"/", nil)
	r.Header.Set("Accept-Encoding", "gzip, br")
	r.Header.Set("Range", "bytes=0-10")
	w := httptest.NewRecorder()
	err := rw.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		o.seen = r
		w.Header().Set("Content-Type", o.contentType)
		if o.encoding != "" {
			w.Header().Set("Content-Encoding", o.encoding)
		}
		if o.chunks == nil {
			w.Header().Set("Content-Length", strconv.Itoa(len(o.body)))
			w.Header().Set("Accept-Ranges", "bytes")
			o.chunks = []string{o.body}
		}
		w.WriteHeader(http.StatusOK)
		for _, chunk := range o.chunks {
			if _, err := w.Write([]byte(chunk)); err != nil {
				return err
			}
			http.NewResponseController(w).Flush()
		}
		return nil
	}))
	if err != nil {
		t.Fatal(err)
	}
	if r.Header.Get("Accept-Encoding") != "gzip, br" || r.Header.Get("Range") == "" {
		t.Error("the visitor's own request was changed, so the access log would lie")
	}
	return w
}

const fooToBar = `{"rewrites":[{"id":1,"hosts":["shop.example"],"find":"foo","replace":"bar","ignore_case":false,"in":"html"}]}`

func TestAnHTMLPageOnARewrittenHostComesOutRewritten(t *testing.T) {
	rw := rewriteHandler(t, fooToBar, rewriteMaxBody)
	o := &rewriteOrigin{contentType: "text/html; charset=utf-8", body: "<p>foo and foo, not Foo</p>"}

	w := serveRewrite(t, rw, "Shop.Example:443", o)

	if got := w.Body.String(); got != "<p>bar and bar, not Foo</p>" {
		t.Errorf("visitor got %q", got)
	}
	if w.Header().Get("Content-Length") != strconv.Itoa(len(w.Body.String())) {
		t.Errorf("Content-Length %q for a %d-byte body", w.Header().Get("Content-Length"), w.Body.Len())
	}
	if w.Header().Get("Accept-Ranges") != "" {
		t.Error("a rewritten page still offers byte ranges")
	}
	if o.seen.Header.Get("Accept-Encoding") != "identity" || o.seen.Header.Get("Range") != "" {
		t.Errorf("origin was asked with Accept-Encoding %q, Range %q", o.seen.Header.Get("Accept-Encoding"), o.seen.Header.Get("Range"))
	}
}

func TestAStreamedPageIsRewrittenWholeEvenAcrossChunks(t *testing.T) {
	rw := rewriteHandler(t, fooToBar, rewriteMaxBody)

	w := serveRewrite(t, rw, "shop.example", &rewriteOrigin{contentType: "text/html", chunks: []string{"<p>f", "oo</p>"}})

	if got := w.Body.String(); got != "<p>bar</p>" {
		t.Errorf("visitor got %q", got)
	}
}

func TestOtherHostsAndOtherContentPassUntouched(t *testing.T) {
	rw := rewriteHandler(t, fooToBar, rewriteMaxBody)

	other := &rewriteOrigin{contentType: "text/html", body: "foo"}
	if got := serveRewrite(t, rw, "blog.example", other).Body.String(); got != "foo" {
		t.Errorf("another host got %q", got)
	}
	if other.seen.Header.Get("Accept-Encoding") != "gzip, br" {
		t.Error("another host's origin lost the visitor's Accept-Encoding")
	}

	for _, o := range []*rewriteOrigin{
		{contentType: "application/javascript", body: "var foo = 1"},
		{contentType: "image/png", body: "foo"},
		{contentType: "text/html", encoding: "gzip", body: "foo"},
	} {
		if got := serveRewrite(t, rw, "shop.example", o).Body.String(); got != o.body {
			t.Errorf("%s (%s) got %q", o.contentType, o.encoding, got)
		}
	}
}

func TestATextRewriteReachesScriptsAndCanIgnoreCaseAndRemove(t *testing.T) {
	rw := rewriteHandler(t, `{"rewrites":[
		{"id":1,"hosts":["shop.example"],"find":"old co","replace":"New Co","ignore_case":true,"in":"text"},
		{"id":2,"hosts":["shop.example"],"find":" [beta]","replace":"","ignore_case":false,"in":"text"}
	]}`, rewriteMaxBody)

	o := &rewriteOrigin{contentType: "application/javascript", body: `title = "Old Co [beta]"; OLD CO`}
	if got := serveRewrite(t, rw, "shop.example", o).Body.String(); got != `title = "New Co"; New Co` {
		t.Errorf("visitor got %q", got)
	}
}

func TestAResponseOverTheCapGoesOnAsItCame(t *testing.T) {
	rw := rewriteHandler(t, fooToBar, 8)

	if got := serveRewrite(t, rw, "shop.example", &rewriteOrigin{contentType: "text/html", body: "foo foo foo"}).Body.String(); got != "foo foo foo" {
		t.Errorf("a page announced over the cap got %q", got)
	}
	if got := serveRewrite(t, rw, "shop.example", &rewriteOrigin{contentType: "text/html", chunks: []string{"foo ", "foo ", "foo"}}).Body.String(); got != "foo foo foo" {
		t.Errorf("a streamed page past the cap got %q", got)
	}
}

func TestARewriteThatWouldComeOutOverTwiceTheCapGoesOnAsItCame(t *testing.T) {
	for _, ignoreCase := range []string{"false", "true"} {
		rw := rewriteHandler(t, `{"rewrites":[
			{"id":1,"hosts":["shop.example"],"find":"b","replace":"bb","ignore_case":false,"in":"html"},
			{"id":2,"hosts":["shop.example"],"find":"a","replace":"xxxxxxxxxx","ignore_case":`+ignoreCase+`,"in":"html"}
		]}`, 16)

		if got := serveRewrite(t, rw, "shop.example", &rewriteOrigin{contentType: "text/html", body: "baa"}).Body.String(); got != "bbxxxxxxxxxxxxxxxxxxxx" {
			t.Errorf("ignore_case %s: a page that stays under twice the cap got %q", ignoreCase, got)
		}
		if got := serveRewrite(t, rw, "shop.example", &rewriteOrigin{contentType: "text/html", body: "baAaa"}).Body.String(); got != "baAaa" {
			t.Errorf("ignore_case %s: a page that would grow past twice the cap got %q", ignoreCase, got)
		}
	}
}

func TestRewritesMustBeAList(t *testing.T) {
	if _, err := parseRewrites([]byte(`{"something":"else"}`)); err == nil {
		t.Error("an answer without a rewrites list was taken")
	}
	rewrites, err := parseRewrites([]byte(`{"rewrites":[{"hosts":["a.example"],"find":"","replace":"x"}]}`))
	if err != nil {
		t.Fatal(err)
	}
	if len(rewrites) != 0 {
		t.Errorf("a rewrite with nothing to find was kept: %v", rewrites)
	}
}

const attToSafe = `{"rewrites":[{"id":1,"hosts":["safe.example"],"find":"att.com","replace":"safe.example","ignore_case":true,"in":"html","headers":true}]}`

// serveHeaders sends method for host through the handler to an origin answering with these headers and
// a PNG body, and returns what the visitor got.
func serveHeaders(t *testing.T, rw *Rewrite, method, host string, headers http.Header) *httptest.ResponseRecorder {
	t.Helper()
	r := httptest.NewRequest(method, "https://"+host+"/", nil)
	w := httptest.NewRecorder()
	err := rw.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		for name, values := range headers {
			w.Header()[name] = append([]string(nil), values...)
		}
		w.WriteHeader(http.StatusFound)
		if r.Method != http.MethodHead {
			_, err := w.Write([]byte("att.com"))
			return err
		}
		return nil
	}))
	if err != nil {
		t.Fatal(err)
	}
	return w
}

func TestAHeaderRewriteChangesCookiesAndRedirectsOnAnyResponse(t *testing.T) {
	rw := rewriteHandler(t, attToSafe, rewriteMaxBody)
	origin := http.Header{
		"Content-Type": {"image/png; name=att.com"},
		"Location":     {"https://www.ATT.com/deals"},
		"Set-Cookie":   {"ixp=1; Path=/; Domain=att.com; Secure", "12160=ad; path=/; domain=.att.com", "UUID=2; Path=/"},
	}

	for _, method := range []string{http.MethodGet, http.MethodHead} {
		w := serveHeaders(t, rw, method, "safe.example", origin)

		if got := w.Header().Get("Location"); got != "https://www.safe.example/deals" {
			t.Errorf("%s: Location %q", method, got)
		}
		want := []string{"ixp=1; Path=/; Domain=safe.example; Secure", "12160=ad; path=/; domain=.safe.example", "UUID=2; Path=/"}
		if got := w.Header().Values("Set-Cookie"); strconv.Quote(strings.Join(got, "|")) != strconv.Quote(strings.Join(want, "|")) {
			t.Errorf("%s: Set-Cookie %q", method, got)
		}
		if got := w.Header().Get("Content-Type"); got != "image/png; name=att.com" {
			t.Errorf("%s: Content-Type changed to %q", method, got)
		}
		if method == http.MethodGet && w.Body.String() != "att.com" {
			t.Errorf("an image's body was changed to %q", w.Body.String())
		}
	}
	if origin.Get("Location") != "https://www.ATT.com/deals" {
		t.Error("the origin's own header map was changed")
	}
}

func TestBodyOnlyRewritesLeaveHeadersAndHeadRequestsAlone(t *testing.T) {
	rw := rewriteHandler(t, `{"rewrites":[{"id":1,"hosts":["safe.example"],"find":"att.com","replace":"safe.example","ignore_case":false,"in":"text"}]}`, rewriteMaxBody)

	for _, method := range []string{http.MethodGet, http.MethodHead} {
		w := serveHeaders(t, rw, method, "safe.example", http.Header{"Location": {"https://att.com/"}})
		if got := w.Header().Get("Location"); got != "https://att.com/" {
			t.Errorf("%s: a body-only rewrite changed Location to %q", method, got)
		}
	}
}

func TestAHeaderReplacementCantStartAHeaderOfItsOwn(t *testing.T) {
	rw := rewriteHandler(t, `{"rewrites":[{"id":1,"hosts":["safe.example"],"find":"att.com","replace":"a\r\nX-Evil: 1\nb","in":"html","headers":true}]}`, rewriteMaxBody)

	w := serveHeaders(t, rw, http.MethodHead, "safe.example", http.Header{"Location": {"https://att.com/"}})

	if got := w.Header().Get("Location"); got != "https://a X-Evil: 1 b/" {
		t.Errorf("Location %q", got)
	}
}

func TestAHeaderRewriteThatWouldComeOutTooLongLeavesTheValueAlone(t *testing.T) {
	rw := rewriteHandler(t, `{"rewrites":[{"id":1,"hosts":["safe.example"],"find":"a","replace":"`+strings.Repeat("x", 1000)+`","in":"html","headers":true}]}`, rewriteMaxBody)
	long := "https://example.com/" + strings.Repeat("a", 20)

	w := serveHeaders(t, rw, http.MethodHead, "safe.example", http.Header{"Location": {long}, "X-Short": {"a"}})

	if got := w.Header().Get("Location"); got != long {
		t.Errorf("Location grew to %d bytes", len(got))
	}
	if got := w.Header().Get("X-Short"); got != strings.Repeat("x", 1000) {
		t.Errorf("X-Short %q", got)
	}
}
