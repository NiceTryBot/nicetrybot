package nicetrycapture

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"maps"
	"net/http"
	"net/url"
	"regexp"
	"slices"
	"strings"
	"unicode/utf8"
)

// requestLimit is the most of a request body read for inspection. A larger body, an upload, or a
// method without a body passes through to the origin unread.
const requestLimit = 64 * 1024

// watchword is something a team wants to know bots tried: a word inside the email or username
// (identity), a word inside the password (password), or one exact password, known only by its SHA-256
// (leaked_password). Keywords arrive lowercased and match case-insensitively.
type watchword struct {
	ID     int
	Kind   string
	Value  string
	SHA256 string
}

// Signals is what a captured request's credentials gave away, without the credentials themselves.
type Signals struct {
	EmailDomain    string `json:"email_domain,omitempty"`
	PasswordLength int    `json:"password_length,omitempty"`
	Watch          []int  `json:"watch,omitempty"`
}

func (s Signals) empty() bool {
	return s.EmailDomain == "" && s.PasswordLength == 0 && len(s.Watch) == 0
}

var (
	// Form fields that hold a password (and "user[password]", "password_confirmation", …).
	passwordKey = regexp.MustCompile(`(?i)(passw(or)?d|passphrase|pwd|^pass$)`)
	// Form fields that hold whom someone is signing in as.
	identityKey = regexp.MustCompile(`(?i)(e-?mail|user(name)?|login|account|identifier)`)
	// A whole value that is an email address.
	emailAddress = regexp.MustCompile(`^[^@\s]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$`)
)

// readRequestBody reads a POST, PUT or PATCH body of at most requestLimit bytes and puts an identical
// copy back for the origin. ok is false for other methods, uploads and bodies too large to inspect;
// whatever was read of those is put back too.
func readRequestBody(r *http.Request) (body []byte, ok bool) {
	if !hasRequestBody(r) || strings.HasPrefix(strings.ToLower(r.Header.Get("Content-Type")), "multipart/") || r.ContentLength > requestLimit {
		return nil, false
	}

	buf, err := io.ReadAll(io.LimitReader(r.Body, requestLimit+1))
	r.Body = replayBody{Reader: io.MultiReader(bytes.NewReader(buf), r.Body), Closer: r.Body}
	if err != nil || len(buf) > requestLimit {
		return nil, false
	}
	return buf, true
}

func hasRequestBody(r *http.Request) bool {
	switch r.Method {
	case http.MethodPost, http.MethodPut, http.MethodPatch:
		return r.Body != nil && r.Body != http.NoBody
	}
	return false
}

// replayBody serves the bytes already read, then the rest of the original body, and closes the original.
type replayBody struct {
	io.Reader
	io.Closer
}

// RedactRequest masks secrets and personal data in a request body: form fields one by one, anything
// else the way Redact handles a response.
func RedactRequest(body []byte, contentType string, maxBytes int) string {
	if !isForm(contentType) {
		return Redact(body, contentType, maxBytes)
	}

	values, err := url.ParseQuery(string(body))
	if err != nil {
		return Redact(body, "text/plain", maxBytes)
	}
	// Left unescaped: this is an excerpt for people to read, not a body to replay.
	var pairs []string
	for _, key := range slices.Sorted(maps.Keys(values)) {
		for _, v := range values[key] {
			if sensitiveKey.MatchString(key) {
				v = "[redacted]"
			} else {
				v = redactValue(v).(string)
			}
			pairs = append(pairs, key+"="+v)
		}
	}
	return truncate(strings.Join(pairs, "&"), maxBytes)
}

// Inspect reads the credentials in a request body and reports the email domain, the password's
// length and which watchwords they hit. Nothing it returns can rebuild the password or the address.
func Inspect(body []byte, contentType string, watch []watchword) Signals {
	var s Signals
	hit := map[int]bool{}

	for _, f := range credentialFields(body, contentType) {
		if f.value == "" {
			continue
		}
		lower := strings.ToLower(f.value)

		switch {
		case passwordKey.MatchString(f.key):
			if s.PasswordLength == 0 {
				s.PasswordLength = utf8.RuneCountInString(f.value)
			}
			sum := sha256.Sum256([]byte(f.value))
			digest := hex.EncodeToString(sum[:])
			for _, w := range watch {
				if (w.Kind == "password" && w.Value != "" && strings.Contains(lower, w.Value)) || (w.Kind == "leaked_password" && w.SHA256 == digest) {
					hit[w.ID] = true
				}
			}
		case identityKey.MatchString(f.key) || emailAddress.MatchString(f.value):
			if m := emailAddress.FindStringSubmatch(f.value); m != nil && s.EmailDomain == "" {
				s.EmailDomain = strings.ToLower(m[1])
			}
			for _, w := range watch {
				if w.Kind == "identity" && w.Value != "" && strings.Contains(lower, w.Value) {
					hit[w.ID] = true
				}
			}
		}
	}

	for _, w := range watch {
		if hit[w.ID] {
			s.Watch = append(s.Watch, w.ID)
			delete(hit, w.ID)
		}
	}
	return s
}

// field is one named value a visitor sent.
type field struct{ key, value string }

// credentialFields flattens a form, a JSON body or a Livewire payload into named values, in a stable
// order. Livewire v2 sends {"name": "email", "value": "…"} pairs, read as the field they stand for. A
// Livewire v3 snapshot (JSON inside a string) is the server's state, not what was typed, so it's skipped.
func credentialFields(body []byte, contentType string) []field {
	var fields []field

	if json.Valid(body) {
		var decoded any
		dec := json.NewDecoder(bytes.NewReader(body))
		dec.UseNumber()
		if dec.Decode(&decoded) == nil {
			walkFields("", decoded, &fields)
		}
		return fields
	}

	if isForm(contentType) || strings.Contains(string(body), "=") {
		if values, err := url.ParseQuery(string(body)); err == nil {
			for _, key := range slices.Sorted(maps.Keys(values)) {
				for _, v := range values[key] {
					fields = append(fields, field{key, v})
				}
			}
		}
	}
	return fields
}

func walkFields(key string, v any, fields *[]field) {
	switch value := v.(type) {
	case map[string]any:
		name, named := value["name"].(string)
		_, hasValue := value["value"]
		for _, k := range slices.Sorted(maps.Keys(value)) {
			if named && hasValue && k == "value" {
				walkFields(name, value[k], fields)
			} else {
				walkFields(k, value[k], fields)
			}
		}
	case []any:
		for _, child := range value {
			walkFields(key, child, fields)
		}
	case string:
		*fields = append(*fields, field{key, value})
	}
}

// jsonInString decodes a string that is itself a JSON object, like a Livewire v3 snapshot.
func jsonInString(s string) (any, bool) {
	trimmed := strings.TrimSpace(s)
	if !strings.HasPrefix(trimmed, "{") || !json.Valid([]byte(trimmed)) {
		return nil, false
	}
	var decoded any
	dec := json.NewDecoder(strings.NewReader(trimmed))
	dec.UseNumber()
	if dec.Decode(&decoded) != nil {
		return nil, false
	}
	return decoded, true
}

func isForm(contentType string) bool {
	return strings.Contains(strings.ToLower(contentType), "x-www-form-urlencoded")
}
