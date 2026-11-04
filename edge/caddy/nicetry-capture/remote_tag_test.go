package nicetrycapture

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// A tag as NiceTryBot's RemoteTag::sign() makes one: v1.<payload>.<43-character signature>.
const wellFormedTag = "v1.eyJiIjoidXNlcnMiLCJyIjoiNDIiLCJ4IjoxODAwMDAzNjAwLCJuIjoiYWJjIn0.d3JvbmctYnV0LXdlbGwtZm9ybWVkLXNpZ25hdHVyZS1oZXJlLXh4eA"

func remoteTag(t *testing.T) *RemoteTag {
	t.Helper()
	h := new(RemoteTag)
	if err := h.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return h
}

func serveTagged(t *testing.T, tag string, status int) *httptest.ResponseRecorder {
	t.Helper()
	rec := httptest.NewRecorder()
	err := remoteTag(t).ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "https://claims.example/login", nil), caddyhttp.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) error {
		w.Header().Set("Location", "/dashboard")
		if tag != "" {
			w.Header().Set(remoteTagHeader, tag)
		}
		w.WriteHeader(status)
		_, err := w.Write([]byte("ok"))
		return err
	}))
	if err != nil {
		t.Fatal(err)
	}
	return rec
}

func TestATagFromTheSiteBecomesACookieTheVisitorNeverSeesAsAHeader(t *testing.T) {
	rec := serveTagged(t, wellFormedTag, http.StatusFound)

	if rec.Header().Get(remoteTagHeader) != "" {
		t.Error("the tag header reached the visitor")
	}
	cookie := rec.Header().Get("Set-Cookie")
	want := remoteTagCookie + "=" + wellFormedTag + "; Path=/; Max-Age=86400; HttpOnly; Secure; SameSite=Lax"
	if cookie != want {
		t.Errorf("cookie = %q, want %q", cookie, want)
	}
	if rec.Code != http.StatusFound || rec.Header().Get("Location") != "/dashboard" || rec.Body.String() != "ok" {
		t.Errorf("the response changed: %d %q %q", rec.Code, rec.Header().Get("Location"), rec.Body.String())
	}
}

func TestAMalformedTagIsDroppedWithoutACookie(t *testing.T) {
	for _, tag := range []string{"hello", "v1.abc.def", wellFormedTag + "; Domain=evil.example", "v2" + strings.TrimPrefix(wellFormedTag, "v1")} {
		rec := serveTagged(t, tag, http.StatusOK)
		if rec.Header().Get(remoteTagHeader) != "" || rec.Header().Get("Set-Cookie") != "" {
			t.Errorf("%q: header %q, cookie %q", tag, rec.Header().Get(remoteTagHeader), rec.Header().Get("Set-Cookie"))
		}
	}
}

func TestAnUntaggedResponseIsUntouched(t *testing.T) {
	rec := serveTagged(t, "", http.StatusOK)
	if len(rec.Header().Values("Set-Cookie")) != 0 || rec.Body.String() != "ok" {
		t.Errorf("cookies %v, body %q", rec.Header().Values("Set-Cookie"), rec.Body.String())
	}
}

func TestATaggedVisitorsCookieGoesToTheGymCheckAndGetsThemAsked(t *testing.T) {
	if !gymCookies[remoteTagCookie] {
		t.Fatal("the gym check never hears of the tag cookie")
	}

	// A cookie trigger: limited to no client and no rule, so only gym cookies get a request asked about.
	trigger := target{Hosts: map[string]bool{"claims.example": true}, Limited: true}
	tagged := httptest.NewRequest(http.MethodGet, "https://claims.example/claims/42", nil)
	tagged.AddCookie(&http.Cookie{Name: remoteTagCookie, Value: wellFormedTag})
	untagged := httptest.NewRequest(http.MethodGet, "https://claims.example/claims/42", nil)

	if !trigger.admits(tagged) {
		t.Error("a tagged visitor isn't asked about")
	}
	if trigger.admits(untagged) {
		t.Error("an untagged visitor is asked about")
	}
}
