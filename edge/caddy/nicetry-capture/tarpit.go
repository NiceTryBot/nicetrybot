package nicetrycapture

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"path"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"
)

func init() {
	caddy.RegisterModule(Tarpit{})
	httpcaddyfile.RegisterHandlerDirective("nicetry_tarpit", parseTarpitCaddyfile)
}

const (
	// tarpitMaxPayload caps how much of the app's answer is read: a tarpit lists every client a rule
	// put in it, so it can run large.
	tarpitMaxPayload = 16 << 20
	// tarpitMaxTracked caps the clients one tarpit keeps count of in each of its tallies. Past it, new
	// clients go uncounted until the sweep frees room: memory stays bounded whatever a flood does.
	tarpitMaxTracked = 100_000
	// tarpitDefaultMaxHeld is how many requests the edge holds at once across every tarpit, unless the
	// Caddyfile says otherwise.
	tarpitDefaultMaxHeld = 1000
	// tarpitSkipPrefix is the gym's own traffic (and the edge's other /_q/ routes): never held, so a
	// visitor clearing the gym isn't slowed by the tarpit that sent it there.
	tarpitSkipPrefix = "/_q/"
)

// Tarpit is the nicetry_tarpit handler: it makes the clients a team puts in a tarpit wait. Each client
// gets the tarpit's free tries, then each further request is held a little longer than the last (2 s,
// 8 s, 24 s…, up to the tarpit's cap) before it goes on, gets a 404, or is hung up on. A client that
// goes quiet for the tarpit's forget time starts over.
//
// A client is in a tarpit when NiceTryBot lists it (the verdicts of the rules the tarpit takes, or a
// gym no-show), when the request matches one of those rules that one request can decide, or, by the
// edge's own count, when it collects too many 404s or sends a burst of requests within the tarpit's
// window. The edge counts on its own because rules land a minute or more after the requests they judge.
//
// A tarpit can also hold the origin's answer when its status is one of the tarpit's statuses (a 404, a
// 403, a 500…, from the rules the app sends that read only the status), before the answer goes back. Any
// tarpit may have a cooldown ladder: a client quiet for its current try's cooldown (the cooldowns in turn)
// steps back one try. A request is held by one tarpit at most, and a status hold lets a static asset
// through when the tarpit spares them: a visitor's broken image, stylesheet or script link never waits.
//
// The older trap form (a tarpit that knows nothing of clients, holding requests containing one of its
// strings or answers with one of its statuses) is still read, for an app that sends it.
//
// Swarm mode handles a distributed flood: when more than a tarpit's swarm count of distinct IPs are held
// within its swarm window (each too quiet on its own to climb the ladder), every new IP skips the ladder
// and waits the cap at once, until the flood lets up. Such a hold is logged with tarpit_swarm.
//
// Holding costs a goroutine and a connection, so each tarpit caps the requests it holds at once, and
// the handler caps them across every tarpit (max_held). A request past a cap is answered at once: a 429,
// let through, or hung up on, as the tarpit says. A held request ends early when its client gives up.
//
// Each request a tarpit touched is logged with tarpit (its id), tarpit_why, tarpit_try, tarpit_ms (how
// long it was held) and tarpit_outcome (free, held, full or left), and action blocked when the tarpit
// answered it instead of the origin.
type Tarpit struct {
	Endpoint string         `json:"endpoint,omitempty"`
	Token    string         `json:"token,omitempty"`
	Interval caddy.Duration `json:"interval,omitempty"`
	// MaxHeld caps the requests held at once across every tarpit on this edge.
	MaxHeld int `json:"max_held,omitempty"`

	set *tarpitSet
}

// CaddyModule returns the Caddy module information.
func (Tarpit) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.nicetry_tarpit",
		New: func() caddy.Module { return new(Tarpit) },
	}
}

// Provision starts (or joins) the poller for the endpoint.
func (t *Tarpit) Provision(caddy.Context) error {
	if t.Interval <= 0 {
		t.Interval = caddy.Duration(10 * time.Second)
	}
	if t.MaxHeld <= 0 {
		t.MaxHeld = tarpitDefaultMaxHeld
	}
	t.set = sharedTarpits(t.Endpoint, t.Token, time.Duration(t.Interval), t.MaxHeld)
	return nil
}

// Validate checks the handler has somewhere to ask and a token to ask with.
func (t *Tarpit) Validate() error {
	if t.Endpoint == "" || t.Token == "" {
		return fmt.Errorf("nicetry_tarpit needs an endpoint and a token")
	}
	return nil
}

func (t *Tarpit) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	// Cleaned, so /_q/../login (which the origin gets as /login) is held like /login.
	if strings.HasPrefix(cleanedPath(r), tarpitSkipPrefix) {
		return next.ServeHTTP(w, r)
	}
	// An allowed client (nicetry_allow) is never held, even by the edge's own burst and 404 counts.
	if isAllowed(r) {
		return next.ServeHTTP(w, r)
	}
	pits := t.set.current.Load().byHost[strings.ToLower(hostOnly(r.Host))]
	if len(pits) == 0 {
		return next.ServeHTTP(w, r)
	}

	now := time.Now()
	ip := lockdownClientIP(r)
	ja4 := ""
	if repl, ok := r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer); ok {
		ja4 = repl.ReplaceAll("{tls.ja4}", "")
	}

	var pit *tarpitPit
	var why string
	for _, p := range pits {
		burst := p.countBurst(ip, now)
		if pit == nil {
			if why = p.admits(r, ip, ja4, burst, now); why != "" {
				pit = p
			}
		}
	}

	if pit != nil {
		if answered, err := t.set.hold(pit, why, ip, w, r, now); answered {
			return err
		}
	}

	// A request for a static asset (a visitor's broken image, stylesheet, script or font link) is let
	// through a 404 count or status trap that spares assets (skipAssets, from Tarpit Pro): a real page
	// with one dead reference mustn't tarpit the reader. Probe traps and client tarpits already ran above.
	isAsset := isAssetPath(cleanedPath(r))

	// A request a tarpit already took isn't held again for its answer's status.
	var trap *tarpitPit
	if pit == nil {
		if t := statusTrap(pits); t != nil && !(t.skipAssets && isAsset) {
			trap = t
		}
	}
	if trap == nil && !anyCountsNotFound(pits) {
		return next.ServeHTTP(w, r)
	}
	rec := &statusRecorder{ResponseWriter: w}
	var out http.ResponseWriter = rec
	var trapped *statusTrapWriter
	if trap != nil {
		trapped = &statusTrapWriter{ResponseWriter: rec, req: r, statuses: trap.statuses, hold: func() { t.set.holdAnswer(trap, "status", ip, r) }}
		out = trapped
	}
	err := next.ServeHTTP(out, r)
	status := rec.status()
	if he, ok := err.(caddyhttp.HandlerError); ok && he.StatusCode != 0 {
		status = he.StatusCode
		// Nothing was written: the server answers the error after this returns, so hold it here.
		if trapped != nil && trap.statuses[status] && !trapped.decided {
			trapped.decided = true
			trapped.hold()
		}
	}
	// Only the origin's 404s count: the gym answering a routine's exit with a 404 isn't the client's.
	if status == http.StatusNotFound && !gymAnswered(r) {
		for _, p := range pits {
			if !(p.skipAssets && isAsset) {
				p.countNotFound(ip, time.Now())
			}
		}
	}
	return err
}

// tarpitPit is one tarpit, as the app described it, with the edge's tallies for it.
type tarpitPit struct {
	id       int
	free     int
	delays   []time.Duration
	cap      time.Duration
	maxHeld  int64
	whenFull string // reject (429), pass, or hangup
	after    string // continue, not_found, or hangup
	forget   time.Duration

	// catches is what a trap catches: "probe" (a request containing one of probes) or "status" (an answer
	// whose status is one of statuses). "" for a tarpit its clients are put in.
	catches   string
	probes    []string
	statuses  map[int]bool    // the response statuses a status trap holds (404, 403, 500…)
	cooldowns []time.Duration // the quiet needed to step back from each strike, in turn

	clients map[string]map[string]string // ip → ja4 ("" for any) → why
	byJA4   map[string]string            // ja4 → why, for clients listed by fingerprint alone
	rules   []edgeRule
	env     *ruleEnv

	notFound *tarpitLimit
	burst    *tarpitLimit

	// swarmCount and swarmWindow are swarm mode: when more than swarmCount distinct IPs are held within
	// swarmWindow, every new IP waits the cap at once instead of climbing the ladder. Off when zero.
	swarmCount  int
	swarmWindow time.Duration

	// skipAssets lets a static asset through a 404 count or status trap (Tarpit Pro). Off, assets are
	// held like any other 404.
	skipAssets bool

	tally *tarpitTally
}

// tarpitLimit is "count within": that many 404s (or requests) from one client within the window puts
// it in the tarpit.
type tarpitLimit struct {
	count  int
	within time.Duration
}

// tarpitTally is what the edge counts for one tarpit, kept across polls while the tarpit lasts.
type tarpitTally struct {
	mu       sync.Mutex
	tries    map[string]*tarpitTries
	notFound map[string]*tarpitWindow
	burst    map[string]*tarpitWindow
	held     atomic.Int64

	// swarm tracks, for swarm mode, each IP last held and whether more than the tarpit's count were held
	// within its window. swarmOn is recomputed at most once a second (swarmCheckedAt), so the hot path
	// only reads it.
	swarm          map[string]time.Time
	swarmOn        bool
	swarmCheckedAt time.Time
}

type tarpitTries struct {
	n    int
	last time.Time
}

// tarpitWindow counts one client's requests (or 404s) in a fixed window. Once over the limit, the client
// is flagged: in the tarpit until it has been quiet for the tarpit's forget time.
type tarpitWindow struct {
	n       int
	start   time.Time
	flagged time.Time // zero when not flagged; else the last request since it was
}

func newTarpitTally() *tarpitTally {
	return &tarpitTally{tries: map[string]*tarpitTries{}, notFound: map[string]*tarpitWindow{}, burst: map[string]*tarpitWindow{}, swarm: map[string]time.Time{}}
}

// admits says why the request belongs in the tarpit, or "" when it doesn't.
func (p *tarpitPit) admits(r *http.Request, ip, ja4 string, burst bool, now time.Time) string {
	if p.catches != "" {
		if p.catches == "probe" && p.probed(r) {
			return "probe"
		}
		return ""
	}
	if byJA4, ok := p.clients[ip]; ok {
		if why, ok := byJA4[""]; ok {
			return why
		}
		if why, ok := byJA4[ja4]; ok && ja4 != "" {
			return why
		}
	}
	if why, ok := p.byJA4[ja4]; ok && ja4 != "" {
		return why
	}
	if burst {
		return "burst"
	}
	if p.notFound != nil && p.flagged(p.tally.notFound, ip, now) {
		return "not_found"
	}
	if len(p.rules) > 0 {
		facts := factsFor(r, p.env, sharedGeo())
		for _, rule := range p.rules {
			if facts.holds(rule.Expr) {
				return "rule"
			}
		}
	}
	return ""
}

// probed says whether the request's path or query contains one of the trap's strings, ignoring case.
// The path is the cleaned, decoded one, so %2eenv and /x/../.env are caught as .env.
func (p *tarpitPit) probed(r *http.Request) bool {
	query := r.URL.RawQuery
	if unescaped, err := url.QueryUnescape(query); err == nil {
		query = unescaped
	}
	target := strings.ToLower(cleanedPath(r) + "?" + query)
	for _, probe := range p.probes {
		if strings.Contains(target, probe) {
			return true
		}
	}
	return false
}

// tarpitAssetExts are the extensions a 404 trap lets through: the static files a browser fetches on its
// own (styles, scripts, source maps, images, fonts, media). A dead link to one is a page's problem, not a
// scan, so it never counts as a 404. Probe matching ignores this: a probe string still catches .env & co.
var tarpitAssetExts = map[string]bool{
	"css": true, "js": true, "mjs": true, "cjs": true, "map": true,
	"png": true, "jpg": true, "jpeg": true, "gif": true, "webp": true, "avif": true,
	"svg": true, "svgz": true, "ico": true, "bmp": true,
	"woff": true, "woff2": true, "ttf": true, "otf": true, "eot": true,
	"mp4": true, "webm": true, "mov": true, "mp3": true, "wav": true, "ogg": true, "m4a": true,
}

// isAssetPath reports whether a path names a static asset by its extension.
func isAssetPath(p string) bool {
	ext := strings.ToLower(strings.TrimPrefix(path.Ext(p), "."))
	return ext != "" && tarpitAssetExts[ext]
}

// statusTrap is the first of the host's tarpits that holds answers by status, or nil: a status trap, or
// a tarpit whose rules hold some statuses.
func statusTrap(pits []*tarpitPit) *tarpitPit {
	for _, p := range pits {
		if p.catches == "status" || (p.catches == "" && len(p.statuses) > 0) {
			return p
		}
	}
	return nil
}

// statusTrapWriter holds an answer before its header goes back to the client when its status is one the
// trap holds, and passes every other answer straight through.
type statusTrapWriter struct {
	http.ResponseWriter
	req      *http.Request
	statuses map[int]bool
	hold     func()
	decided  bool
}

// WriteHeader holds the answer when its status is one the trap holds and it's the origin's: the gym's
// or the Bot Bouncer's own 403 (or 404, or 429) is never held, or the challenge would wait on itself.
func (w *statusTrapWriter) WriteHeader(code int) {
	if !w.decided && code >= 200 {
		w.decided = true
		if w.statuses[code] && !gymAnswered(w.req) {
			w.hold()
		}
	}
	w.ResponseWriter.WriteHeader(code)
}

func (w *statusTrapWriter) Write(b []byte) (int, error) {
	w.decided = true
	return w.ResponseWriter.Write(b)
}

func (w *statusTrapWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

// countBurst counts the request towards the tarpit's burst limit and says whether the client is over it
// (or still flagged from going over it).
func (p *tarpitPit) countBurst(ip string, now time.Time) bool {
	if p.burst == nil {
		return false
	}
	return p.count(p.tally.burst, p.burst, ip, now)
}

// countNotFound counts a 404 the client got towards the tarpit's limit.
func (p *tarpitPit) countNotFound(ip string, now time.Time) {
	if p.notFound != nil {
		p.count(p.tally.notFound, p.notFound, ip, now)
	}
}

func (p *tarpitPit) count(windows map[string]*tarpitWindow, limit *tarpitLimit, ip string, now time.Time) bool {
	p.tally.mu.Lock()
	defer p.tally.mu.Unlock()

	win, ok := windows[ip]
	if !ok {
		if len(windows) >= tarpitMaxTracked {
			return false
		}
		win = &tarpitWindow{start: now}
		windows[ip] = win
	}
	if !win.flagged.IsZero() {
		if now.Sub(win.flagged) <= p.forget {
			win.flagged = now
			return true
		}
		win.flagged = time.Time{}
		win.n, win.start = 0, now
	}
	if now.Sub(win.start) > limit.within {
		win.n, win.start = 0, now
	}
	win.n++
	if win.n >= limit.count {
		win.flagged = now
		return true
	}
	return false
}

// flagged says whether a tally has the client over its limit and not yet quiet for the forget time,
// and keeps the flag up for this request.
func (p *tarpitPit) flagged(windows map[string]*tarpitWindow, ip string, now time.Time) bool {
	p.tally.mu.Lock()
	defer p.tally.mu.Unlock()

	win, ok := windows[ip]
	if !ok || win.flagged.IsZero() {
		return false
	}
	if now.Sub(win.flagged) > p.forget {
		win.flagged = time.Time{}
		win.n, win.start = 0, now
		return false
	}
	win.flagged = now
	return true
}

// try counts the client's request in the tarpit and returns which try it is, from 1. A client that was
// quiet for the forget time starts over. In a trap each try is a strike, and before counting it the
// client steps back one strike for each stretch of quiet lasting that strike's cooldown (the trap's
// cooldowns in turn, the last one holding past the ladder's end).
func (p *tarpitPit) try(ip string, now time.Time) int {
	p.tally.mu.Lock()
	defer p.tally.mu.Unlock()
	n, _ := p.tryLocked(ip, now)
	return n
}

// tryLocked is try's body, with the tally already locked. It also reports whether the client is a
// newcomer: it had no live strike before this request (brand new, or stepped/forgotten all the way back),
// which is who swarm mode sends straight to the cap.
func (p *tarpitPit) tryLocked(ip string, now time.Time) (int, bool) {
	tries, ok := p.tally.tries[ip]
	if !ok {
		if len(p.tally.tries) >= tarpitMaxTracked {
			return p.free + 1, true
		}
		tries = &tarpitTries{}
		p.tally.tries[ip] = tries
	}
	quiet := now.Sub(tries.last)
	if quiet > p.forget {
		tries.n = 0
	}
	for len(p.cooldowns) > 0 && tries.n > 0 {
		step := p.cooldownFor(tries.n)
		if quiet < step {
			break
		}
		quiet -= step
		tries.n--
	}
	newcomer := tries.n == 0
	tries.n++
	tries.last = now
	return tries.n, newcomer
}

// waitFor counts the request and returns how long to hold it, and whether swarm mode set that wait. In
// swarm mode (more than swarmCount IPs held within swarmWindow) a newcomer skips the ladder and waits the
// cap at once. Every held request (its wait over zero) is recorded, so the swarm sustains itself while
// new IPs keep coming and lapses once they stop.
func (p *tarpitPit) waitFor(ip string, now time.Time) (int, time.Duration, bool) {
	p.tally.mu.Lock()
	defer p.tally.mu.Unlock()

	try, newcomer := p.tryLocked(ip, now)
	delay := p.delayFor(try)
	swarmed := false
	if p.swarmCount > 0 && newcomer && p.swarmActiveLocked(now) {
		delay, swarmed = p.cap, true
	}
	if delay > 0 {
		if _, ok := p.tally.swarm[ip]; ok || len(p.tally.swarm) < tarpitMaxTracked {
			p.tally.swarm[ip] = now
		}
	}
	return try, delay, swarmed
}

// swarmActiveLocked reports whether more than swarmCount distinct IPs have been held within swarmWindow.
// It re-counts (pruning IPs past the window) at most once a second, so a flood of held requests doesn't
// each pay an O(n) scan; between recounts the last answer stands.
func (p *tarpitPit) swarmActiveLocked(now time.Time) bool {
	if now.Sub(p.tally.swarmCheckedAt) >= time.Second {
		cutoff := now.Add(-p.swarmWindow)
		for ip, last := range p.tally.swarm {
			if last.Before(cutoff) {
				delete(p.tally.swarm, ip)
			}
		}
		p.tally.swarmOn = len(p.tally.swarm) > p.swarmCount
		p.tally.swarmCheckedAt = now
	}
	return p.tally.swarmOn
}

// cooldownFor is how long a client must be quiet to step back from the given strike: the trap's cooldowns
// in turn, the last one holding for every strike past the ladder's end.
func (p *tarpitPit) cooldownFor(strike int) time.Duration {
	if len(p.cooldowns) == 0 || strike <= 0 {
		return 0
	}
	if strike > len(p.cooldowns) {
		strike = len(p.cooldowns)
	}
	return p.cooldowns[strike-1]
}

// delayFor is how long the given try is held: nothing for the free tries, then each delay in turn, and
// the cap from then on (no delay ever past it).
func (p *tarpitPit) delayFor(try int) time.Duration {
	paid := try - p.free
	if paid <= 0 {
		return 0
	}
	delay := p.cap
	if paid <= len(p.delays) {
		delay = p.delays[paid-1]
	}
	if delay > p.cap {
		delay = p.cap
	}
	return delay
}

// sweep forgets clients that have been quiet long enough that their counts no longer matter.
func (p *tarpitPit) sweep(now time.Time) {
	p.tally.mu.Lock()
	defer p.tally.mu.Unlock()

	for ip, tries := range p.tally.tries {
		if now.Sub(tries.last) > p.forget {
			delete(p.tally.tries, ip)
		}
	}
	for _, pair := range []struct {
		windows map[string]*tarpitWindow
		limit   *tarpitLimit
	}{{p.tally.notFound, p.notFound}, {p.tally.burst, p.burst}} {
		for ip, win := range pair.windows {
			within := time.Duration(0)
			if pair.limit != nil {
				within = pair.limit.within
			}
			if win.flagged.IsZero() && now.Sub(win.start) > within || !win.flagged.IsZero() && now.Sub(win.flagged) > p.forget {
				delete(pair.windows, ip)
			}
		}
	}
	cutoff := now.Add(-p.swarmWindow)
	for ip, last := range p.tally.swarm {
		if last.Before(cutoff) {
			delete(p.tally.swarm, ip)
		}
	}
}

func anyCountsNotFound(pits []*tarpitPit) bool {
	for _, p := range pits {
		if p.notFound != nil {
			return true
		}
	}
	return false
}

// hold makes the request wait as long as its try calls for. It reports whether it answered the request
// itself (a 429, a 404, a hang-up, or a client that left), in which case nothing further in runs.
func (s *tarpitSet) hold(p *tarpitPit, why, ip string, w http.ResponseWriter, r *http.Request, now time.Time) (bool, error) {
	try, delay, swarmed := p.waitFor(ip, now)
	if delay == 0 {
		logTarpit(r, p.id, why, try, 0, "free", false, swarmed)
		return false, nil
	}

	if !s.acquire(p) {
		switch p.whenFull {
		case "pass":
			logTarpit(r, p.id, why, try, 0, "full", false, swarmed)
			return false, nil
		case "hangup":
			logTarpit(r, p.id, why, try, 0, "full", true, swarmed)
			return true, hangUp(w)
		default:
			logTarpit(r, p.id, why, try, 0, "full", true, swarmed)
			w.Header().Set("Retry-After", strconv.Itoa(int(delay.Seconds())))
			w.Header().Set("Cache-Control", "no-store")
			w.WriteHeader(http.StatusTooManyRequests)
			return true, nil
		}
	}
	defer s.release(p)

	held, left := waitOut(r, delay)
	if left {
		logTarpit(r, p.id, why, try, held, "left", true, swarmed)
		return true, nil
	}

	switch p.after {
	case "not_found":
		logTarpit(r, p.id, why, try, held, "held", true, swarmed)
		w.Header().Set("Cache-Control", "no-store")
		w.WriteHeader(http.StatusNotFound)
		return true, nil
	case "hangup":
		logTarpit(r, p.id, why, try, held, "held", true, swarmed)
		return true, hangUp(w)
	}
	logTarpit(r, p.id, why, try, held, "held", false, swarmed)
	return false, nil
}

// holdAnswer makes the origin's answer wait as long as the strike calls for, before it goes back. The
// origin has already had the request, so nothing the tarpit does here keeps it off: when the tarpit is
// full, or the client leaves, the answer just goes back.
func (s *tarpitSet) holdAnswer(p *tarpitPit, why, ip string, r *http.Request) {
	try, delay, swarmed := p.waitFor(ip, time.Now())
	if delay == 0 {
		logTarpit(r, p.id, why, try, 0, "free", false, swarmed)
		return
	}
	if !s.acquire(p) {
		logTarpit(r, p.id, why, try, 0, "full", false, swarmed)
		return
	}
	defer s.release(p)

	held, left := waitOut(r, delay)
	outcome := "held"
	if left {
		outcome = "left"
	}
	logTarpit(r, p.id, why, try, held, outcome, false, swarmed)
}

// waitOut waits for the delay, or until the client gives up, and says how long it waited and whether
// the client left.
func waitOut(r *http.Request, delay time.Duration) (time.Duration, bool) {
	start := time.Now()
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-timer.C:
		return time.Since(start), false
	case <-r.Context().Done():
		return time.Since(start), true
	}
}

// acquire takes a hold slot from the tarpit and from the edge, or reports that either is full.
func (s *tarpitSet) acquire(p *tarpitPit) bool {
	if p.tally.held.Add(1) > p.maxHeld {
		p.tally.held.Add(-1)
		return false
	}
	if s.held.Add(1) > s.maxHeld.Load() {
		s.held.Add(-1)
		p.tally.held.Add(-1)
		return false
	}
	return true
}

func (s *tarpitSet) release(p *tarpitPit) {
	p.tally.held.Add(-1)
	s.held.Add(-1)
}

// hangUp closes the connection without an answer, where the protocol lets one connection go (HTTP/1);
// on HTTP/2 it answers an empty 404 instead, so other streams on the connection are left alone.
func hangUp(w http.ResponseWriter) error {
	if conn, _, err := http.NewResponseController(w).Hijack(); err == nil {
		return conn.Close()
	}
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(http.StatusNotFound)
	return nil
}

// logTarpit records what the tarpit did in the access log, once per request. A tarpit that answered the
// request itself kept it off the origin: action blocked.
func logTarpit(r *http.Request, id int, why string, try int, held time.Duration, outcome string, answered, swarmed bool) {
	extra, ok := r.Context().Value(caddyhttp.ExtraLogFieldsCtxKey).(*caddyhttp.ExtraLogFields)
	if !ok {
		return
	}
	extra.Add(zap.Int("tarpit", id))
	extra.Add(zap.String("tarpit_why", why))
	extra.Add(zap.Int("tarpit_try", try))
	extra.Add(zap.Int64("tarpit_ms", held.Milliseconds()))
	extra.Add(zap.String("tarpit_outcome", outcome))
	if swarmed {
		extra.Add(zap.Bool("tarpit_swarm", true))
	}
	if answered {
		extra.Add(zap.String("action", "blocked"))
	}
}

// tarpitList is the tarpits by hostname, each host's in the order the app gave them (the first one a
// request belongs in holds it).
type tarpitList struct {
	byHost map[string][]*tarpitPit
}

type tarpitSet struct {
	current atomic.Pointer[tarpitList]
	held    atomic.Int64
	maxHeld atomic.Int64

	// Only the poller touches these.
	endpoint, token string
	etag            string
	raw             []byte
	tallies         map[int]*tarpitTally // by tarpit id, kept while the tarpit is listed
}

var (
	tarpitSetsMu sync.Mutex
	tarpitSets   = map[string]*tarpitSet{}
)

// sharedTarpits returns one poller per endpoint and token, shared by every site and kept across reloads,
// as are the counts. The edge-wide cap is the largest any site's handler asks for.
func sharedTarpits(endpoint, token string, interval time.Duration, maxHeld int) *tarpitSet {
	tarpitSetsMu.Lock()
	defer tarpitSetsMu.Unlock()

	key := endpoint + "\x00" + token
	if s, ok := tarpitSets[key]; ok {
		if int64(maxHeld) > s.maxHeld.Load() {
			s.maxHeld.Store(int64(maxHeld))
		}
		return s
	}

	s := newTarpitSet(endpoint, token, maxHeld)
	tarpitSets[key] = s
	go s.poll(interval)
	return s
}

func newTarpitSet(endpoint, token string, maxHeld int) *tarpitSet {
	s := &tarpitSet{endpoint: endpoint, token: token, tallies: map[int]*tarpitTally{}}
	s.current.Store(&tarpitList{byHost: map[string][]*tarpitPit{}})
	s.maxHeld.Store(int64(maxHeld))
	return s
}

// restore starts the tarpits from their last good copy.
func (s *tarpitSet) restore(store *lastGood) bool {
	var raw []byte
	list, ok := restoreList(store, "tarpit", func(body []byte) (*tarpitList, error) {
		raw = body
		return s.parse(body)
	})
	if ok {
		s.current.Store(list)
		s.raw = raw
	}
	return ok
}

func (s *tarpitSet) poll(interval time.Duration) {
	client := &http.Client{Timeout: 10 * time.Second}
	s.restore(sharedLastGood())
	for {
		if err := s.refresh(client); err != nil {
			caddy.Log().Named("nicetry_tarpit").Warn("keeping the last tarpits", zap.Error(err))
		}
		s.sweep(time.Now())
		time.Sleep(interval)
	}
}

// refresh asks the app for the current tarpits. Anything but a good answer leaves the last list in
// place; an unchanged one (304, or the same body) changes nothing.
func (s *tarpitSet) refresh(client *http.Client) error {
	raw, etag, err := fetchTarpits(client, s.endpoint, s.token, s.etag)
	if err != nil || raw == nil {
		return err
	}
	if bytes.Equal(raw, s.raw) {
		s.etag = etag
		return nil
	}
	list, err := s.parse(raw)
	if err != nil {
		return err
	}
	s.current.Store(list)
	s.etag, s.raw = etag, raw
	sharedLastGood().save("tarpit", raw)
	caddy.Log().Named("nicetry_tarpit").Info("tarpits updated", zap.Int("hosts", len(list.byHost)), zap.Int("tarpits", len(s.tallies)))
	return nil
}

// sweep forgets quiet clients in every listed tarpit.
func (s *tarpitSet) sweep(now time.Time) {
	seen := map[int]bool{}
	for _, pits := range s.current.Load().byHost {
		for _, p := range pits {
			if !seen[p.id] {
				seen[p.id] = true
				p.sweep(now)
			}
		}
	}
}

func fetchTarpits(client *http.Client, endpoint, token, etag string) ([]byte, string, error) {
	req, err := http.NewRequest(http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, "", err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}

	resp, err := client.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotModified {
		return nil, etag, nil
	}
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("tarpits returned %d", resp.StatusCode)
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, tarpitMaxPayload+1))
	if err != nil {
		return nil, "", err
	}
	if len(raw) > tarpitMaxPayload {
		return nil, "", fmt.Errorf("tarpits answer is over %d bytes", tarpitMaxPayload)
	}
	return raw, resp.Header.Get("ETag"), nil
}

// parse turns the app's answer into tarpits by hostname, keeping each tarpit's counts from the last
// list. The answer must name its tarpits (an empty list means none), so an unrelated 200 can't empty it.
func (s *tarpitSet) parse(raw []byte) (*tarpitList, error) {
	var payload map[string]json.RawMessage
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}
	rawList, ok := payload["tarpits"]
	if !ok {
		return nil, fmt.Errorf("tarpits answer has no tarpits")
	}

	type limit struct {
		Count  int `json:"count"`
		Within int `json:"within"`
	}
	var entries []struct {
		ID       int      `json:"id"`
		Hosts    []string `json:"hosts"`
		Free     int      `json:"free"`
		Delays   []int    `json:"delays"`
		Cap      int      `json:"cap"`
		MaxHeld  int      `json:"max_held"`
		WhenFull string   `json:"when_full"`
		After    string   `json:"after"`
		Forget   int      `json:"forget"`
		Clients  []struct {
			IP  *string `json:"ip"`
			JA4 *string `json:"ja4"`
			Why string  `json:"why"`
		} `json:"clients"`
		Rules []struct {
			ID   int       `json:"id"`
			Expr *ruleExpr `json:"expr"`
		} `json:"rules"`
		NotFound   *limit `json:"not_found"`
		Burst      *limit `json:"burst"`
		Swarm      *limit `json:"swarm"`
		SkipAssets bool   `json:"skip_assets"`
		Statuses   []int  `json:"statuses"`
		Cooldowns  []int  `json:"cooldowns"`
		Trap       *struct {
			Catches   string   `json:"catches"`
			Strings   []string `json:"strings"`
			Statuses  []int    `json:"statuses"`
			Cooldowns []int    `json:"cooldowns"`
		} `json:"trap"`
	}
	if err := json.Unmarshal(rawList, &entries); err != nil {
		return nil, err
	}

	env := ruleEnvFrom(payload)
	sort.SliceStable(entries, func(i, j int) bool { return entries[i].ID < entries[j].ID })

	list := &tarpitList{byHost: map[string][]*tarpitPit{}}
	tallies := map[int]*tarpitTally{}
	for _, e := range entries {
		if e.ID <= 0 || len(e.Hosts) == 0 {
			continue
		}
		tally, ok := s.tallies[e.ID]
		if !ok {
			tally = newTarpitTally()
		}
		tallies[e.ID] = tally

		p := &tarpitPit{
			id:       e.ID,
			free:     max(e.Free, 0),
			cap:      time.Duration(clamp(e.Cap, 1, 300)) * time.Second,
			maxHeld:  int64(max(e.MaxHeld, 1)),
			whenFull: e.WhenFull,
			after:    e.After,
			forget:   time.Duration(max(e.Forget, 1)) * time.Second,
			clients:  map[string]map[string]string{},
			byJA4:    map[string]string{},
			env:      env,
			tally:    tally,
		}
		if e.Swarm != nil && e.Swarm.Count > 0 && e.Swarm.Within > 0 {
			p.swarmCount = e.Swarm.Count
			p.swarmWindow = time.Duration(e.Swarm.Within) * time.Second
		}
		p.skipAssets = e.SkipAssets
		for _, seconds := range e.Cooldowns {
			p.cooldowns = append(p.cooldowns, time.Duration(clamp(seconds, 1, 120))*time.Second)
		}
		for _, status := range e.Statuses {
			if status >= 100 && status <= 599 {
				if p.statuses == nil {
					p.statuses = map[int]bool{}
				}
				p.statuses[status] = true
			}
		}
		if e.Trap != nil && (e.Trap.Catches == "probe" || e.Trap.Catches == "status") {
			p.catches = e.Trap.Catches
			p.cooldowns = nil
			for _, seconds := range e.Trap.Cooldowns {
				p.cooldowns = append(p.cooldowns, time.Duration(clamp(seconds, 1, 120))*time.Second)
			}
			for _, probe := range e.Trap.Strings {
				if probe = strings.ToLower(strings.TrimSpace(probe)); probe != "" {
					p.probes = append(p.probes, probe)
				}
			}
			p.statuses = map[int]bool{}
			for _, status := range e.Trap.Statuses {
				if status >= 100 && status <= 599 {
					p.statuses[status] = true
				}
			}
			// A probe trap with nothing to look for, or a status trap with no status, catches nothing; skip it.
			if (p.catches == "probe" && len(p.probes) == 0) || (p.catches == "status" && len(p.statuses) == 0) {
				continue
			}
		}
		for _, d := range e.Delays {
			p.delays = append(p.delays, time.Duration(clamp(d, 0, 300))*time.Second)
		}
		for _, c := range e.Clients {
			why := c.Why
			if why == "" {
				why = "client"
			}
			var ip, ja4 string
			if c.IP != nil {
				ip = *c.IP
			}
			if c.JA4 != nil {
				ja4 = *c.JA4
			}
			switch {
			case ip != "":
				if p.clients[ip] == nil {
					p.clients[ip] = map[string]string{}
				}
				p.clients[ip][ja4] = why
			case ja4 != "":
				p.byJA4[ja4] = why
			}
		}
		for _, rule := range e.Rules {
			if rule.Expr != nil {
				rule.Expr.compile()
				p.rules = append(p.rules, edgeRule{ID: rule.ID, Expr: rule.Expr})
			}
		}
		if e.NotFound != nil && e.NotFound.Count > 0 && e.NotFound.Within > 0 {
			p.notFound = &tarpitLimit{count: e.NotFound.Count, within: time.Duration(e.NotFound.Within) * time.Second}
		}
		if e.Burst != nil && e.Burst.Count > 0 && e.Burst.Within > 0 {
			p.burst = &tarpitLimit{count: e.Burst.Count, within: time.Duration(e.Burst.Within) * time.Second}
		}
		for _, h := range e.Hosts {
			host := strings.ToLower(h)
			list.byHost[host] = append(list.byHost[host], p)
		}
	}
	s.tallies = tallies
	return list, nil
}

func clamp(n, lo, hi int) int {
	return min(max(n, lo), hi)
}

//	nicetry_tarpit {
//	    endpoint <url>
//	    token    <token>
//	    interval <duration>
//	    max_held <count>
//	}
func (t *Tarpit) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	for d.NextBlock(0) {
		key := d.Val()
		var raw string
		if !d.Args(&raw) {
			return d.ArgErr()
		}
		switch key {
		case "endpoint":
			t.Endpoint = raw
		case "token":
			t.Token = raw
		case "interval":
			dur, err := caddy.ParseDuration(raw)
			if err != nil {
				return d.Errf("interval: %v", err)
			}
			t.Interval = caddy.Duration(dur)
		case "max_held":
			n, err := strconv.Atoi(raw)
			if err != nil || n <= 0 {
				return d.Errf("max_held must be a positive number, not %q", raw)
			}
			t.MaxHeld = n
		default:
			return d.Errf("unknown nicetry_tarpit option %q", key)
		}
	}
	return nil
}

func parseTarpitCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	t := new(Tarpit)
	err := t.UnmarshalCaddyfile(h.Dispenser)
	return t, err
}

var (
	_ caddy.Provisioner           = (*Tarpit)(nil)
	_ caddy.Validator             = (*Tarpit)(nil)
	_ caddyhttp.MiddlewareHandler = (*Tarpit)(nil)
	_ caddyfile.Unmarshaler       = (*Tarpit)(nil)
)
