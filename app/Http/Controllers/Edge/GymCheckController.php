<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Gym\EnterGym;
use App\Actions\Gym\MatchGymRoutines;
use App\Actions\Rules\ReachLiveVerdicts;
use App\Data\GymVisitor;
use App\Data\LiveRequest;
use App\Enums\DomainStatus;
use App\Enums\GymExit;
use App\Enums\GymPassState;
use App\Enums\GymSessionStatus;
use App\Enums\ProtectionMode;
use App\Gym\GymPipeline;
use App\Gym\GymVisit;
use App\Http\Controllers\Controller;
use App\Models\ClientHint;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class GymCheckController extends Controller
{
    /**
     * The session cookie a background request's reload carries before the visitor has a session: no
     * token matches it, so it only gets the reload asked about.
     */
    private const string RELOAD_MARKER = 'reload';

    public function __construct(private GymPipeline $pipeline, private MatchGymRoutines $matchGymRoutines, private ReachLiveVerdicts $reachLiveVerdicts)
    {
        //
    }

    /**
     * Decide, for one request the edge flagged as a gym trigger, whether the visitor trains first.
     *
     * 204 lets the request through to the origin: the domain isn't enforcing, the visitor carries a
     * valid pass, or no routine wants them. Otherwise the answer is the gym itself, which the edge
     * serves in place of the page, so the address bar never leaves the page they asked for. When they
     * finish, the gym reloads that page with a pass. A session cookie lets a reload pick up where the
     * visitor left off. The edge describes the visitor in X-NiceTry-* headers and forwards only the
     * gym's own cookies, never the site's.
     *
     * A background request (Livewire, fetch) can't show a page, so a visitor who belongs in the gym is
     * told to reload the page they're on instead, where the gym then appears in place. For Livewire v2
     * that's a redirect in its own response format. No session starts for the background URL itself,
     * but a visitor without a session cookie gets a placeholder one: the edge asks about any request
     * carrying a gym cookie, so the reload reaches the gym even when the page isn't a trigger (a rule
     * that leaves page paths out, or a verdict reached just now that the edge's client list doesn't
     * have yet). Without it the page loads untouched, and its next background request bounces it again.
     *
     * A pass from a one-try routine allows page loads and a single form submission. Once spent, the
     * visitor goes back to the gym, except for a page load that could lift their verdict (a rule's
     * UNTIL, e.g. the dashboard after signing in): that goes through provisionally, and the edge is
     * asked (X-NiceTry-Report) to report its status to /caddy/gym-report, which settles it.
     *
     * An edge that judges single-request rules (ListGymTriggersController::RULES_FEATURE) describes the
     * client too (language, place, network, edge tag) and names the rules it saw the request match
     * (X-NiceTry-Rules). Those that truly match reach their verdicts here, before routines are matched,
     * so a rule's gym takes the client in on the very request that earned the verdict.
     */
    public function __invoke(Request $request, EnterGym $enterGym): Response
    {
        $domain = $this->enforcingDomain(Str::lower((string) $request->header('X-NiceTry-Host')));

        if ($domain === null) {
            return response()->noContent();
        }

        $uri = '/'.ltrim((string) $request->header('X-NiceTry-Uri', '/'), '/');
        $pass = $request->cookie(GymSession::PASS_COOKIE);
        $passSession = is_string($pass) ? GymSession::fromPass($pass, $domain->team_id) : null;
        $visitor = $this->visitor($request, $domain, $uri, $passSession ?? $this->cookieSession($request, $domain));

        $this->reachLiveVerdicts->handle($domain->team, $this->liveRequest($request, $domain, $visitor), $this->flaggedRules($request));

        if ($this->matchGymRoutines->handle($domain->team, $visitor)->isEmpty()) {
            return response()->noContent();
        }

        if ($passSession !== null && ($allowed = $this->honourPass($passSession, $request, $domain, $visitor)) !== null) {
            return $allowed;
        }

        if ($this->isBackgroundRequest($request)) {
            $response = $this->reloadPage($uri, $this->pageTheyAreOn($request, $domain));

            if (! $request->hasCookie(GymSession::SESSION_COOKIE)) {
                $response->headers->setCookie(cookie(GymSession::SESSION_COOKIE, self::RELOAD_MARKER, 5, secure: $request->isSecure(), sameSite: 'lax'));
            }

            return $response;
        }

        $session = $this->resumableSession($request, $domain) ?? $enterGym->handle($domain->team, $visitor, destination: $uri, domain: $domain);

        if ($session === null) {
            return response()->noContent();
        }

        // An edge that passes the visitor's client hints on lets the session keep them from the start.
        $session->rememberClientHints($visitor->ip, ClientHint::valueOf($request->header('Sec-CH-UA'), $request->header('Sec-CH-UA-Platform')));

        if ($session->isDirty('client_hints_id')) {
            $session->save();
        }

        $response = $this->pipeline->run(new GymVisit($session, $this->pipeline->plan($session), $request));
        $response->headers->setCookie(cookie(GymSession::SESSION_COOKIE, $session->token, (int) config('gym.idle_minutes'), secure: $request->isSecure(), sameSite: 'lax'));

        return $response;
    }

    /**
     * Let a pass holder through, or return null when the pass no longer covers this request. A time
     * pass covers everything until it expires; a one-try pass follows GymPassState.
     */
    private function honourPass(GymSession $session, Request $request, Domain $domain, GymVisitor $visitor): ?Response
    {
        if (! $session->hasOneTryPass()) {
            return response()->noContent();
        }

        $isPageLoad = in_array($this->method($request), ['GET', 'HEAD'], true);

        if ($session->pass_state === null) {
            if (! $isPageLoad) {
                $session->update(['pass_state' => GymPassState::Spent]);
            }

            return response()->noContent();
        }

        if (! $isPageLoad) {
            return null;
        }

        if ($session->pass_state === GymPassState::Provisional) {
            return response()->noContent();
        }

        if (! $this->couldLiftVerdict($domain, $visitor, $request)) {
            return null;
        }

        $session->update(['pass_state' => GymPassState::Provisional]);

        return response()->noContent()->header('X-NiceTry-Report', '1');
    }

    /**
     * Determine if the request might satisfy the UNTIL of a rule holding a verdict on the visitor. The
     * status isn't known yet, so a request is a candidate unless it certainly can't.
     */
    private function couldLiftVerdict(Domain $domain, GymVisitor $visitor, Request $request): bool
    {
        $live = new LiveRequest($this->method($request), $domain->hostname, (string) $visitor->path, ip: $visitor->ip, ja4: $visitor->ja4, userAgent: $visitor->userAgent);

        return EdgeRule::query()
            ->whereIn('id', $this->matchGymRoutines->verdictRuleIds($domain->team, $visitor))
            ->get()
            ->contains(fn (EdgeRule $rule) => ($until = rescue(fn () => $rule->script()->until, report: false)) !== null && $live->evaluate($until) !== false);
    }

    private function method(Request $request): string
    {
        return strtoupper((string) $request->header('X-NiceTry-Method', 'GET'));
    }

    /**
     * Find the session the visitor's cookie points at, if they can carry on with it: still training,
     * or turned away (so a reload shows the same exit instead of a fresh workout).
     */
    private function resumableSession(Request $request, Domain $domain): ?GymSession
    {
        $session = $this->cookieSession($request, $domain);

        return match (true) {
            $session === null => null,
            $session->status === GymSessionStatus::Training => $session->hasGivenUp() ? null : $session,
            default => $session->exit_action !== null && $session->exit_action !== GymExit::Continue ? $session : null,
        };
    }

    /**
     * Determine if the request was made by a script on a page rather than by the browser loading one.
     */
    private function isBackgroundRequest(Request $request): bool
    {
        $accept = (string) $request->header('Accept');

        return $request->hasHeader('X-Livewire')
            || $request->header('X-Requested-With') === 'XMLHttpRequest'
            || (str_contains($accept, 'json') && ! str_contains($accept, 'text/html'));
    }

    /**
     * Find the active, enforcing domain the edge served a hostname as: its own domain (or the one it's
     * the preview of), or site for www.site when site serves www.
     */
    private function enforcingDomain(string $hostname): ?Domain
    {
        $enforcing = fn () => Domain::query()
            ->where('status', DomainStatus::Active)
            ->where('protection_mode', ProtectionMode::Enforce);

        $bare = Domain::withoutWww($hostname);

        return $enforcing()->servedAs($hostname)->first()
            ?? ($bare === null ? null : $enforcing()->where('hostname', $bare)->servingWww()->first());
    }

    /**
     * Get the page a background request came from (its Referer on the same domain), or the home page.
     */
    private function pageTheyAreOn(Request $request, Domain $domain): string
    {
        $referer = parse_url((string) $request->header('Referer'));

        if (! is_array($referer) || ! in_array(Str::lower($referer['host'] ?? ''), $domain->edgeHostnames(), true)) {
            return '/';
        }

        return '/'.ltrim($referer['path'] ?? '/', '/').(isset($referer['query']) ? '?'.$referer['query'] : '');
    }

    /**
     * Tell a background request's page to reload, in Livewire v2's response format when it's a
     * Livewire v2 message so its script follows the redirect.
     */
    private function reloadPage(string $uri, string $page): JsonResponse
    {
        $body = str_starts_with($uri, '/livewire/message/')
            ? ['effects' => ['html' => null, 'dirty' => [], 'redirect' => $page], 'serverMemo' => new stdClass]
            : ['redirect' => $page];

        return response()->json($body)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Find the session the visitor's gym session cookie points at, in this team's gym.
     */
    private function cookieSession(Request $request, Domain $domain): ?GymSession
    {
        $token = $request->cookie(GymSession::SESSION_COOKIE);

        return is_string($token) ? GymSession::query()
            ->where('token', $token)
            ->where('team_id', $domain->team_id)
            ->where('is_preview', false)
            ->first() : null;
    }

    /**
     * Describe the visitor. A browser that has been in the gym is known by the TLS fingerprint it
     * entered with, carried by its gym cookies: browsers present a different JA4 when they resume a
     * TLS session or move to HTTP/3, which would otherwise let them slip their verdict.
     */
    private function visitor(Request $request, Domain $domain, string $uri, ?GymSession $known = null): GymVisitor
    {
        $ip = (string) $request->header('X-NiceTry-Client-Ip');
        $asn = $this->described($request, 'X-NiceTry-ASN');

        return new GymVisitor(
            ip: filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0',
            host: $domain->hostname,
            path: (string) parse_url($uri, PHP_URL_PATH),
            userAgent: $request->userAgent(),
            ja4: $known?->ja4 ?? ($request->header('X-NiceTry-JA4') ?: null),
            countryCode: $this->described($request, 'X-NiceTry-Country'),
            asn: is_numeric($asn) ? (int) $asn : null,
            tag: $this->described($request, 'X-NiceTry-Tag'),
        );
    }

    /**
     * Describe the request to rules as it happens, with what the edge says about the client.
     */
    private function liveRequest(Request $request, Domain $domain, GymVisitor $visitor): LiveRequest
    {
        return new LiveRequest(
            method: $this->method($request),
            host: $domain->hostname,
            path: (string) $visitor->path,
            ip: $visitor->ip,
            ja4: $visitor->ja4,
            userAgent: $visitor->userAgent,
            described: $this->describesClient($request),
            language: $request->header('Accept-Language') ?: null,
            countryCode: $visitor->countryCode,
            region: $this->described($request, 'X-NiceTry-Region'),
            city: $this->described($request, 'X-NiceTry-City'),
            asn: $visitor->asn,
            network: $this->described($request, 'X-NiceTry-Network'),
            tag: $visitor->tag,
        );
    }

    /**
     * Determine if the edge describes the client (it judges rules itself), so that what it leaves out
     * is missing rather than unknown.
     */
    private function describesClient(Request $request): bool
    {
        return in_array(ListGymTriggersController::RULES_FEATURE, array_map('trim', explode(',', (string) $request->header('X-NiceTry-Features'))), true);
    }

    /**
     * Get what the edge says about the client in a header (URL-encoded, since place names and networks
     * aren't always ASCII), or null when it doesn't describe it or the header is empty.
     */
    private function described(Request $request, string $header): ?string
    {
        $value = $this->describesClient($request) ? rawurldecode((string) $request->header($header)) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * Get the rules the edge saw the request match.
     *
     * @return list<int>
     */
    private function flaggedRules(Request $request): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->header('X-NiceTry-Rules'))))));
    }
}
