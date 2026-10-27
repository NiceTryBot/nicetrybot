package nicetrycapture

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// app.notices.example's Filament login: Livewire v3 posts every component to /livewire/update,
// so only the snapshot's memo says which one this is.
const livewireV3Login = `{"_token":"t0k","components":[` +
	`{"snapshot":"{\"data\":{\"data\":[{\"email\":null,\"password\":null},{\"s\":\"arr\"}]},\"memo\":{\"id\":\"a\",\"name\":\"app.filament.auth.login\",\"path\":\"dashboard\/login\"},\"checksum\":\"c\"}",` +
	`"updates":{"data.email":"Jane@Globex.example","data.password":"Tiger2024!"},"calls":[{"path":"","method":"authenticate","params":[]}]},` +
	`{"snapshot":"{\"data\":{},\"memo\":{\"id\":\"b\",\"name\":\"filament.livewire.notifications\"},\"checksum\":\"d\"}","updates":{},"calls":[]}]}`

const livewireV3Table = `{"_token":"t0k","components":[{"snapshot":"{\"data\":{},\"memo\":{\"id\":\"c\",\"name\":\"app.filament.resources.case-resource.pages.list-cases\"},\"checksum\":\"e\"}","updates":{"tableSearch":"smith"},"calls":[]}]}`

func TestLivewireV3UpdatesNameTheirComponents(t *testing.T) {
	got := livewireComponents([]byte(livewireV3Login))
	if want := []string{"app.filament.auth.login", "filament.livewire.notifications"}; !reflect.DeepEqual(got, want) {
		t.Errorf("got %v, want %v", got, want)
	}

	for _, body := range []string{livewireV2Login, "email=a&password=b", `{"components":[{"snapshot":"not json"}]}`} {
		if got := livewireComponents([]byte(body)); got != nil {
			t.Errorf("%s: expected no components, got %v", body, got)
		}
	}
}

func TestLivewireV3LoginGivesSignalsWithoutTheCredentials(t *testing.T) {
	got := Inspect([]byte(livewireV3Login), "application/json", watchlist)

	if want := (Signals{EmailDomain: "globex.example", PasswordLength: 10, Watch: []int{2, 3, 4}}); !reflect.DeepEqual(got, want) {
		t.Errorf("got %+v, want %+v", got, want)
	}
}

// serveLivewire posts a body to app.notices.example/livewire/update through the capture and
// returns what was logged and what the origin received.
func serveLivewire(t *testing.T, targets []target, body string) (map[string]any, string) {
	t.Helper()
	ts := &targetSet{}
	ts.current.Store(&targets)
	capture := &Capture{MaxBytes: 4096, targets: ts}

	extra := new(caddyhttp.ExtraLogFields)
	r := httptest.NewRequest(http.MethodPost, "https://app.notices.example/livewire/update", strings.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.ExtraLogFieldsCtxKey, extra))

	var forwarded string
	origin := caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		b, _ := io.ReadAll(r.Body)
		forwarded = string(b)
		w.Header().Set("Content-Type", "application/json")
		_, err := w.Write([]byte(`{"components":[]}`))
		return err
	})

	if err := capture.ServeHTTP(httptest.NewRecorder(), r, origin); err != nil {
		t.Fatal(err)
	}
	return loggedFields(extra), forwarded
}

var appHost = map[string]bool{"app.notices.example": true}

func TestACaptureOnAComponentRecordsOnlyThatComponent(t *testing.T) {
	login := []target{{ID: 40, Hosts: appHost, Method: "POST", Path: GlobRegexp("/livewire/update"), Component: GlobRegexp("*auth.login"), Request: true, Watch: watchlist}}

	logged, forwarded := serveLivewire(t, login, livewireV3Login)
	if logged["capture_id"] != int64(40) || logged["livewire_component"] != "app.filament.auth.login" {
		t.Errorf("the login wasn't recorded: %v", logged)
	}
	if want := (Signals{EmailDomain: "globex.example", PasswordLength: 10, Watch: []int{2, 3, 4}}); !reflect.DeepEqual(logged["signals"], want) {
		t.Errorf("signals = %v, want %+v", logged["signals"], want)
	}
	if forwarded != livewireV3Login {
		t.Errorf("the origin got a different body: %s", forwarded)
	}

	logged, forwarded = serveLivewire(t, login, livewireV3Table)
	if len(logged) != 0 {
		t.Errorf("another component was logged: %v", logged)
	}
	if forwarded != livewireV3Table {
		t.Errorf("the origin got a different body: %s", forwarded)
	}
}

func TestATagLogsTheComponentAndRecordsNothing(t *testing.T) {
	tag := []target{{ID: 2, Hosts: appHost, Method: "POST", Path: GlobRegexp("/livewire/update"), Component: GlobRegexp("*auth.login"), TagOnly: true}}

	logged, forwarded := serveLivewire(t, tag, livewireV3Login)
	if want := map[string]any{"livewire_component": "app.filament.auth.login"}; !reflect.DeepEqual(logged, want) {
		t.Errorf("logged %v, want %v", logged, want)
	}
	if forwarded != livewireV3Login {
		t.Errorf("the origin got a different body: %s", forwarded)
	}
}

func TestACaptureOnTheWholeRouteStillNamesTheComponentATagWatches(t *testing.T) {
	targets := []target{
		{ID: 41, Hosts: appHost, Path: GlobRegexp("/livewire/*")},
		{ID: 2, Hosts: appHost, Path: GlobRegexp("/livewire/update"), Component: GlobRegexp("*auth.login"), TagOnly: true},
	}

	logged, _ := serveLivewire(t, targets, livewireV3Login)
	if logged["capture_id"] != int64(41) || logged["livewire_component"] != "app.filament.auth.login" {
		t.Errorf("logged %v", logged)
	}
}

func TestTagsArriveWithTheCapturesAndNeverRecord(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"captures":[{"id":3,"hosts":["sign.example"],"path":"/login","request":true,"component":null}],` +
			`"tags":[{"id":2,"hosts":["app.notices.example"],"method":"POST","path":"/livewire/update","request":true,"component":"*auth.login","watch":[{"id":1,"kind":"identity","value":"x"}]}]}`))
	}))
	defer app.Close()

	targets, _, err := fetchTargets(app.Client(), app.URL, "t", "captures", false, "")
	if err != nil || len(targets) != 2 {
		t.Fatalf("got %+v, %v", targets, err)
	}
	if targets[0].TagOnly || targets[0].Component != nil {
		t.Errorf("the capture became a tag: %+v", targets[0])
	}
	if tag := targets[1]; !tag.TagOnly || tag.Request || tag.Watch != nil || !tag.Component.MatchString("app.filament.auth.login") {
		t.Errorf("unexpected tag: %+v", tag)
	}
}
