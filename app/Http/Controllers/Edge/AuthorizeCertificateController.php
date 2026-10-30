<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Domains\ResolveHostname;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEdge;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthorizeCertificateController extends Controller
{
    /**
     * Answer Caddy's on-demand TLS "ask": may the edge obtain a certificate for this hostname?
     *
     * Any 2xx allows issuance. Verified domains qualify, including paused ones, so a paused site that
     * still points at the edge keeps renewing its certificate. So does any hostname a shared origin
     * vouches for, which joins that origin's sites on the way (App\Support\SharedOrigins).
     */
    public function __invoke(Request $request, ResolveHostname $resolveHostname): Response
    {
        // Only the edge may ask: anyone else could test which hostnames are customers, or have a shared
        // origin vouch for names on their say-so. Caddy's ask can't send headers, so its token comes in
        // the query (or as a bearer token). Strangers get the answer an unknown hostname gets.
        if (! $this->askedByEdge($request)) {
            return response('', 404);
        }

        $domain = $resolveHostname->handle((string) $request->query('domain'));

        return response('', $domain ? 200 : 404);
    }

    /**
     * Determine if the edge asked, with its token in the query or as a bearer token.
     */
    private function askedByEdge(Request $request): bool
    {
        $token = (string) config('services.nicetry_edge.token');

        return AuthenticateEdge::isFromEdge($request)
            || ($token !== '' && hash_equals($token, (string) $request->query('token')));
    }
}
