package nicetrycapture

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"unsafe"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap/zapcore"
)

func sha(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

var watchlist = []watchword{
	{ID: 1, Kind: "identity", Value: "acmecorp"},
	{ID: 2, Kind: "password", Value: "tiger"},
	{ID: 3, Kind: "leaked_password", SHA256: sha("Tiger2024!")},
	{ID: 4, Kind: "identity", Value: "globex"},
	{ID: 5, Kind: "identity", Value: "jdoe"},
}

// sign.example's login: Livewire v2 sends each deferred field as a syncInput update with the call.
const livewireV2Login = `{"fingerprint":{"name":"auth.login"},"serverMemo":{"data":{"email":null,"password":null},"checksum":"c1"},` +
	`"updates":[{"type":"syncInput","payload":{"id":"a","name":"email","value":"Jane.Doe@AcmeCorp.example"}},` +
	`{"type":"syncInput","payload":{"id":"b","name":"password","value":"Tiger2024!"}},` +
	`{"type":"callMethod","payload":{"id":"c","method":"authenticate","params":[]}}]}`

func TestLivewireV2LoginGivesSignalsWithoutTheCredentials(t *testing.T) {
	got := Inspect([]byte(livewireV2Login), "application/json", watchlist)

	want := Signals{EmailDomain: "acmecorp.example", PasswordLength: 10, Watch: []int{1, 2, 3}}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("got %+v, want %+v", got, want)
	}

	redacted := RedactRequest([]byte(livewireV2Login), "application/json", 4096)
	for _, leaked := range []string{"Tiger2024!", "Jane.Doe", "c1"} {
		if strings.Contains(redacted, leaked) {
			t.Errorf("leaked %q in %s", leaked, redacted)
		}
	}
	if !strings.Contains(redacted, "J***@AcmeCorp.example") {
		t.Errorf("expected the masked address in %s", redacted)
	}
}

func TestLivewireV3SnapshotIsRedactedAndOnlyTheUpdatesCount(t *testing.T) {
	body := `{"components":[{"snapshot":"{\"data\":{\"email\":\"old@acmecorp.example\",\"password\":\"oldsecret\"},\"checksum\":\"x\"}",` +
		`"updates":{"email":"x@globex.example","password":"hunter2"},"calls":[{"method":"login"}]}]}`

	got := Inspect([]byte(body), "application/json", watchlist)

	want := Signals{EmailDomain: "globex.example", PasswordLength: 7, Watch: []int{4}}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("got %+v, want %+v", got, want)
	}

	redacted := RedactRequest([]byte(body), "application/json", 4096)
	for _, leaked := range []string{"hunter2", "oldsecret"} {
		if strings.Contains(redacted, leaked) {
			t.Errorf("leaked %q in %s", leaked, redacted)
		}
	}
}

func TestFormLoginIsRedactedFieldByFieldAndStaysReadable(t *testing.T) {
	body := []byte("email=jdoe%40example.com&password=hunter2&_token=abc&remember=on")

	redacted := RedactRequest(body, "application/x-www-form-urlencoded", 4096)
	if redacted != "_token=[redacted]&email=j***@example.com&password=[redacted]&remember=on" {
		t.Errorf("unexpected excerpt: %q", redacted)
	}

	got := Inspect(body, "application/x-www-form-urlencoded", watchlist)
	want := Signals{EmailDomain: "example.com", PasswordLength: 7, Watch: []int{5}}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("got %+v, want %+v", got, want)
	}
}

func TestKeywordsMatchRegardlessOfCaseButLeakedPasswordsOnlyExactly(t *testing.T) {
	got := Inspect([]byte(`{"username":"GLOBEX-admin","password":"tiger2024!"}`), "application/json", watchlist)

	// "tiger2024!" contains the keyword, but isn't the leaked "Tiger2024!".
	if want := []int{2, 4}; !reflect.DeepEqual(got.Watch, want) {
		t.Errorf("got %v, want %v", got.Watch, want)
	}
	if got.EmailDomain != "" {
		t.Errorf("a username has no email domain, got %q", got.EmailDomain)
	}
}

func TestOnlySmallNonUploadBodiesAreReadAndTheOriginGetsThemIntact(t *testing.T) {
	big := strings.Repeat("a", requestLimit+1)
	cases := []struct {
		name, method, contentType, body string
		read                            bool
	}{
		{"login", http.MethodPost, "application/json", livewireV2Login, true},
		{"too large", http.MethodPost, "application/x-www-form-urlencoded", big, false},
		{"upload", http.MethodPost, "multipart/form-data; boundary=x", "--x--", false},
		{"no body method", http.MethodGet, "", "", false},
	}

	for _, c := range cases {
		r := httptest.NewRequest(c.method, "https://sign.example/login", strings.NewReader(c.body))
		r.Header.Set("Content-Type", c.contentType)
		if c.name == "too large" {
			r.ContentLength = -1 // chunked: the size is only found by reading
		}

		_, read := readRequestBody(r)
		if read != c.read {
			t.Errorf("%s: read = %v, want %v", c.name, read, c.read)
		}
		if forwarded, _ := io.ReadAll(r.Body); string(forwarded) != c.body {
			t.Errorf("%s: the origin got %d bytes, want %d", c.name, len(forwarded), len(c.body))
		}
	}
}

func TestCapturedLoginLogsTheRedactedRequestAndItsSignals(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 9, Hosts: map[string]bool{"sign.example": true}, Method: "POST", Path: GlobRegexp("/livewire/message/*"), Request: true, Watch: watchlist}})
	capture := &Capture{MaxBytes: 4096, targets: ts}

	extra := new(caddyhttp.ExtraLogFields)
	r := httptest.NewRequest(http.MethodPost, "https://sign.example/livewire/message/auth.login", strings.NewReader(livewireV2Login))
	r.Header.Set("Content-Type", "application/json")
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.ExtraLogFieldsCtxKey, extra))

	var forwarded string
	origin := caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		body, _ := io.ReadAll(r.Body)
		forwarded = string(body)
		w.Header().Set("Content-Type", "application/json")
		_, err := w.Write([]byte(`{"serverMemo":{"errors":{"email":["These credentials do not match our records."]}}}`))
		return err
	})

	if err := capture.ServeHTTP(httptest.NewRecorder(), r, origin); err != nil {
		t.Fatal(err)
	}
	if forwarded != livewireV2Login {
		t.Errorf("the origin got a different body: %s", forwarded)
	}

	logged := loggedFields(extra)
	if logged["request_type"] != "application/json" || logged["request_bytes"] != int64(len(livewireV2Login)) {
		t.Errorf("unexpected request fields: %v", logged)
	}
	if body, _ := logged["request_body"].(string); strings.Contains(body, "Tiger2024!") || !strings.Contains(body, "[redacted]") {
		t.Errorf("request body not redacted: %s", body)
	}
	if want := (Signals{EmailDomain: "acmecorp.example", PasswordLength: 10, Watch: []int{1, 2, 3}}); !reflect.DeepEqual(logged["signals"], want) {
		t.Errorf("signals = %v, want %+v", logged["signals"], want)
	}
}

func TestCapturesWithoutRequestRecordingLogNothingAboutTheRequest(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 9, Hosts: map[string]bool{"sign.example": true}, Path: GlobRegexp("/*"), Watch: watchlist}})
	capture := &Capture{MaxBytes: 4096, targets: ts}

	extra := new(caddyhttp.ExtraLogFields)
	r := httptest.NewRequest(http.MethodPost, "https://sign.example/login", strings.NewReader(livewireV2Login))
	r = r.WithContext(context.WithValue(r.Context(), caddyhttp.ExtraLogFieldsCtxKey, extra))

	if err := capture.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(inner)); err != nil {
		t.Fatal(err)
	}
	for _, key := range []string{"request_type", "request_body", "request_bytes", "signals"} {
		if _, ok := loggedFields(extra)[key]; ok {
			t.Errorf("%s logged on a response-only capture", key)
		}
	}
}

// loggedFields reads back what a handler added to the access log line.
func loggedFields(extra *caddyhttp.ExtraLogFields) map[string]any {
	v := reflect.ValueOf(extra).Elem().FieldByName("fields")
	fields := reflect.NewAt(v.Type(), unsafe.Pointer(v.UnsafeAddr())).Elem().Interface().([]zapcore.Field)

	enc := zapcore.NewMapObjectEncoder()
	for _, f := range fields {
		f.AddTo(enc)
	}
	return enc.Fields
}
