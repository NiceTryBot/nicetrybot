package nicetrycapture

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// liveRulesFixture is testdata/live_rules.json: rules as NiceTryBot sends them, requests, and what
// NiceTryBot's LiveRequest says of each request under each rule.
type liveRulesFixture struct {
	Categories json.RawMessage `json:"categories"`
	Networks   json.RawMessage `json:"networks"`
	Host       string          `json:"host"`
	Requests   []struct {
		Method  string  `json:"method"`
		Path    string  `json:"path"`
		IP      *string `json:"ip"`
		JA4     *string `json:"ja4"`
		UA      *string `json:"ua"`
		Lang    *string `json:"lang"`
		Country *string `json:"country"`
		Region  *string `json:"region"`
		City    *string `json:"city"`
		ASN     *uint   `json:"asn"`
		Network *string `json:"network"`
		Tag     *string `json:"tag"`
	} `json:"requests"`
	Cases []struct {
		Name   string    `json:"name"`
		Where  string    `json:"where"`
		Expr   *ruleExpr `json:"expr"`
		Expect []*bool   `json:"expect"`
	} `json:"cases"`
}

func text(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func TestRulesAreJudgedAsNiceTryBotJudgesThem(t *testing.T) {
	raw, err := os.ReadFile("testdata/live_rules.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixture liveRulesFixture
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	env := ruleEnvFrom(map[string]json.RawMessage{"categories": fixture.Categories, "networks": fixture.Networks})

	for _, c := range fixture.Cases {
		c.Expr.compile()
		for i, req := range fixture.Requests {
			facts := &requestFacts{
				method: req.Method, host: fixture.Host, path: req.Path, ip: text(req.IP), ja4: text(req.JA4), ua: text(req.UA),
				lang: text(req.Lang), tag: text(req.Tag), env: env, located: true,
				place: geoRecord{country: text(req.Country), region: text(req.Region), city: text(req.City), network: text(req.Network)},
			}
			if req.ASN != nil {
				facts.place.asn = *req.ASN
			}

			got, want := c.Expr.eval(facts), c.Expect[i]
			if (got == nil) != (want == nil) || (got != nil && *got != *want) {
				t.Errorf("%s (%s), request %d (%s %s): got %s, NiceTryBot says %s", c.Name, c.Where, i, req.Method, req.Path, show(got), show(want))
			}
		}
	}
}

func show(b *bool) string {
	if b == nil {
		return "unknown"
	}
	return fmt.Sprint(*b)
}

func TestWhereAVisitorComesFromIsUnknownWithoutTheIPDatabase(t *testing.T) {
	expr := &ruleExpr{Kind: "cmp", Field: "country", Op: "!=", Value: json.RawMessage(`"US"`)}
	expr.compile()

	if got := expr.eval(&requestFacts{method: "GET", host: "sign.example", path: "/"}); got != nil {
		t.Fatalf("expected unknown, got %v", *got)
	}
}

// withCaddyVars gives a request the variable table Caddy gives every request.
func withCaddyVars(r *http.Request) *http.Request {
	return r.WithContext(context.WithValue(r.Context(), caddyhttp.VarsCtxKey, map[string]any{}))
}

func TestALimitedTriggerAdmitsItsClientsAndRequestsMatchingItsRules(t *testing.T) {
	feed := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-NiceTry-Features") != gymRulesFeature {
			t.Errorf("expected the edge to announce it judges rules, got %q", r.Header.Get("X-NiceTry-Features"))
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"triggers": [{"id": 7, "hosts": ["sign.example", "www.sign.example"], "method": null, "path": "*", "clients": [{"ip": "203.0.113.7", "ja4": null}], "limited": true,
				"rules": [{"id": 12, "expr": {"k": "and", "l": {"k": "cmp", "f": "host", "op": "=", "v": "sign.example"}, "r": {"k": "in", "f": "category", "vs": [9], "neg": false}}}]}],
			"categories": [{"id": 9, "patterns": ["/phpmyadmin*", "/pma/*"]}],
			"networks": [{"kind": 1, "asns": [14061]}],
			"domains": [{"hostname": "sign.example", "aliases": ["sign.example", "www.sign.example"]}]
		}`))
	}))
	defer feed.Close()

	targets, _, err := fetchTargets(feed.Client(), feed.URL, "t", "triggers", true, "")
	if err != nil {
		t.Fatal(err)
	}
	ts := &targetSet{}
	ts.current.Store(&targets)

	probe := withCaddyVars(httptest.NewRequest(http.MethodGet, "https://www.sign.example/phpMyAdmin.php", nil))
	probe.RemoteAddr = "198.51.100.9:443"
	if ts.match(probe) == nil {
		t.Fatal("expected a request matching the rule to be admitted")
	}
	if got := rememberedRules(probe); len(got) != 1 || got[0] != 12 {
		t.Errorf("expected rule 12 remembered for the gym check, got %v", got)
	}

	page := withCaddyVars(httptest.NewRequest(http.MethodGet, "https://sign.example/pricing", nil))
	page.RemoteAddr = "198.51.100.9:443"
	if ts.match(page) != nil {
		t.Error("expected a request from another client, matching no rule, to go straight through")
	}

	listed := withCaddyVars(httptest.NewRequest(http.MethodGet, "https://sign.example/pricing", nil))
	listed.RemoteAddr = "203.0.113.7:443"
	if ts.match(listed) == nil {
		t.Error("expected a listed client to be admitted")
	}
}

func TestAnUnlimitedTriggerStillAdmitsEveryClient(t *testing.T) {
	ts := &targetSet{}
	ts.current.Store(&[]target{{ID: 1, Hosts: map[string]bool{"sign.example": true}, Path: GlobRegexp("*")}})

	if ts.match(withCaddyVars(httptest.NewRequest(http.MethodGet, "https://sign.example/", nil))) == nil {
		t.Fatal("expected a trigger without clients or limits to admit everyone")
	}
}

func TestTheGymCheckHearsTheMatchedRulesAndTheClientsLanguage(t *testing.T) {
	var seen http.Header
	check := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen = r.Header.Clone()
		w.WriteHeader(http.StatusNoContent)
	}))
	defer check.Close()

	g := gymFor(check.URL)
	r := withCaddyVars(httptest.NewRequest(http.MethodGet, "https://sign.example/gymbros", nil))
	r.Header.Set("Accept-Language", "sq-AL,sq;q=0.9")
	caddyhttp.SetVar(r.Context(), "bot_tag", "probe")
	rememberRules(r, []int{12, 34})
	rememberRules(r, []int{34})
	_ = g.ServeHTTP(httptest.NewRecorder(), r, caddyhttp.HandlerFunc((&origin{}).ServeHTTP))

	for name, want := range map[string]string{"X-NiceTry-Features": "gym-rules", "X-NiceTry-Rules": "12,34", "Accept-Language": "sq-AL,sq;q=0.9", "X-NiceTry-Tag": "probe"} {
		if got := seen.Get(name); got != want {
			t.Errorf("%s: got %q, want %q", name, got, want)
		}
	}
	if got := seen.Get("X-NiceTry-Country"); got != "" {
		t.Errorf("expected no place without the IP database, got %q", got)
	}
}
