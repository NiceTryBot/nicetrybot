<?php

namespace App\Gym;

use App\Models\Domain;
use App\Models\GymSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use stdClass;

/**
 * Requests a script on a page makes (Livewire, fetch) rather than the browser loading one, which can't be
 * shown the gym or the bouncer in place. Whoever stops one tells its page to reload instead, so the
 * challenge appears on the page; a script that only says it's a page's request gets nothing from it.
 */
class BackgroundRequests
{
    /**
     * The session cookie a background request's reload carries before the visitor has a session: no
     * token matches it, so it only gets the reload asked about.
     */
    public const string RELOAD_MARKER = 'reload';

    /**
     * Determine if the request was made by a script on a page rather than by the browser loading one.
     */
    public function isBackground(Request $request): bool
    {
        $accept = (string) $request->header('Accept');

        return $request->hasHeader('X-Livewire')
            || $request->header('X-Requested-With') === 'XMLHttpRequest'
            || (str_contains($accept, 'json') && ! str_contains($accept, 'text/html'));
    }

    /**
     * Tell a background request's page to reload, in Livewire v2's response format when it's a Livewire
     * v2 message so its script follows the redirect. A visitor without a session cookie gets a placeholder
     * one: the edge asks about any request carrying a gym cookie, so the reload reaches the gym or the
     * bouncer even when the page isn't a trigger.
     */
    public function reload(Request $request, Domain $domain, string $uri): JsonResponse
    {
        $page = $this->pageTheyAreOn($request, $domain);
        $body = str_starts_with($uri, '/livewire/message/')
            ? ['effects' => ['html' => null, 'dirty' => [], 'redirect' => $page], 'serverMemo' => new stdClass]
            : ['redirect' => $page];

        $response = response()->json($body)->header('Cache-Control', 'no-store, private');

        if (! $request->hasCookie(GymSession::SESSION_COOKIE)) {
            $response->headers->setCookie(cookie(GymSession::SESSION_COOKIE, self::RELOAD_MARKER, 5, secure: $request->isSecure(), sameSite: 'lax'));
        }

        return $response;
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
}
