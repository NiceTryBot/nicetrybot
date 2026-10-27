package nicetrycapture

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

func phpGuard(t *testing.T) *PHPGuard {
	t.Helper()
	g := new(PHPGuard)
	if err := g.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return g
}

func multipartBody(fields ...string) string {
	var b strings.Builder
	for i := 0; i < len(fields); i += 2 {
		b.WriteString("--x\r\nContent-Disposition: form-data; name=\"" + fields[i] + "\"\r\n\r\n" + fields[i+1] + "\r\n")
	}
	b.WriteString("--x--\r\n")
	return b.String()
}

func guardedRequest(contentType string, body io.Reader) (*http.Request, map[string]any) {
	r := httptest.NewRequest(http.MethodPost, "https://shop.example/anything.php", body)
	r.Header.Set("Content-Type", contentType)
	r.Header.Set("Cookie", "PHPSESSID=attacker")

	vars := map[string]any{}
	ctx := context.WithValue(r.Context(), caddyhttp.VarsCtxKey, vars)
	ctx = context.WithValue(ctx, caddyhttp.ExtraLogFieldsCtxKey, new(caddyhttp.ExtraLogFields))
	return r.WithContext(ctx), vars
}

func TestAnUploadProgressFieldIsRefusedBeforeTheOriginHearsOfIt(t *testing.T) {
	for _, contentType := range []string{"multipart/form-data; boundary=x", "Multipart/Form-Data;boundary=x"} {
		r, vars := guardedRequest(contentType, strings.NewReader(multipartBody(uploadProgressField, "1", "file", "x")))

		reached := false
		err := phpGuard(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(http.ResponseWriter, *http.Request) error {
			reached = true
			return nil
		}))

		var handlerErr caddyhttp.HandlerError
		if !errors.As(err, &handlerErr) || handlerErr.StatusCode != http.StatusForbidden {
			t.Errorf("%s: want a 403, got %v", contentType, err)
		}
		if reached {
			t.Errorf("%s: the origin was reached", contentType)
		}
		if vars["bot_tag"] != "php_upload_progress" {
			t.Errorf("%s: bot_tag = %v", contentType, vars["bot_tag"])
		}
	}
}

func TestOtherBodiesReachTheOriginWhole(t *testing.T) {
	cases := map[string]string{
		"multipart/form-data; boundary=x": multipartBody("email", "a@example.com", "file", strings.Repeat("z", 3*guardPrefix)),
		// PHP only acts on the field in multipart bodies.
		"application/json": `{"note":"` + uploadProgressField + `"}`,
	}
	for contentType, body := range cases {
		r, _ := guardedRequest(contentType, strings.NewReader(body))

		var received []byte
		err := phpGuard(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) error {
			var err error
			received, err = io.ReadAll(r.Body)
			return err
		}))

		if err != nil {
			t.Errorf("%s: %v", contentType, err)
		}
		if string(received) != body {
			t.Errorf("%s: the origin got %d bytes of %d", contentType, len(received), len(body))
		}
	}
}

// splitReader hands its data over in pieces of the given size, so a field name can straddle two reads.
type splitReader struct {
	data string
	size int
}

func (s *splitReader) Read(p []byte) (int, error) {
	if s.data == "" {
		return 0, io.EOF
	}
	n := copy(p, s.data[:min(s.size, len(s.data))])
	s.data = s.data[n:]
	return n, nil
}

// Past the prefix (and the read beyond it), split across reads.
func TestAFieldDeepInALongUploadIsCutOffBeforeTheOriginSeesIt(t *testing.T) {
	body := multipartBody("file", strings.Repeat("z", 2*guardPrefix), uploadProgressField, "1")
	// A read size that ends partway through the field name.
	at, size := strings.Index(body, uploadProgressField), 1000
	for at/size == (at+len(uploadProgressField)-1)/size {
		size++
	}
	r, vars := guardedRequest("multipart/form-data; boundary=x", &splitReader{data: body, size: size})

	var received []byte
	var readErr error
	_ = phpGuard(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) error {
		received, readErr = io.ReadAll(r.Body)
		return readErr
	}))

	if !errors.Is(readErr, errUploadProgress) {
		t.Errorf("the upload should have been cut off, got %v", readErr)
	}
	if len(received) < guardPrefix {
		t.Errorf("the origin should have had the start of the upload, got %d bytes", len(received))
	}
	if strings.Contains(string(received), "PHP_SESSION") {
		t.Error("part of the field name reached the origin")
	}
	if vars["bot_tag"] != "php_upload_progress" {
		t.Errorf("bot_tag = %v", vars["bot_tag"])
	}
}

func strictPHPGuard(t *testing.T) *PHPGuard {
	t.Helper()
	g := &PHPGuard{BlockMultipart: true}
	if err := g.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return g
}

func serveStrict(t *testing.T, r *http.Request) (error, bool) {
	t.Helper()
	reached := false
	err := strictPHPGuard(t).ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc(func(http.ResponseWriter, *http.Request) error {
		reached = true
		return nil
	}))
	return err, reached
}

func TestEveryMultipartUploadIsRefusedWhenBlocked(t *testing.T) {
	r, vars := guardedRequest("multipart/form-data; boundary=x", strings.NewReader(multipartBody("email", "a@example.com")))

	err, reached := serveStrict(t, r)

	var handlerErr caddyhttp.HandlerError
	if !errors.As(err, &handlerErr) || handlerErr.StatusCode != http.StatusForbidden {
		t.Errorf("want a 403, got %v", err)
	}
	if reached {
		t.Error("the origin was reached")
	}
	if vars["bot_tag"] != "php_multipart" {
		t.Errorf("bot_tag = %v", vars["bot_tag"])
	}
}

func TestOtherRequestsPassTheStrictGuard(t *testing.T) {
	r, _ := guardedRequest("application/x-www-form-urlencoded", strings.NewReader("email=a%40example.com"))

	err, reached := serveStrict(t, r)

	if err != nil || !reached {
		t.Errorf("want the origin reached, got %v (reached %v)", err, reached)
	}
}
