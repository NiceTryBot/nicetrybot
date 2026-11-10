<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Domains\ResolveHostname;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEdge;
use App\Rules\Hostname;
use App\Support\CertificateHostnames;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AuthorizeCertificateController extends Controller
{
    /**
     * Answer Caddy's on-demand TLS "ask": may the edge obtain a certificate for this hostname?
     *
     * Any 2xx allows issuance. Verified domains qualify, including paused ones, so a paused site that
     * still points at the edge keeps renewing its certificate. So does any hostname a shared origin
     * vouches for, which joins that origin's sites on the way (App\Support\SharedOrigins), and one an
     * add-on has the edge answer on itself (App\Support\CertificateHostnames).
     */
    public function __invoke(Request $request, ResolveHostname $resolveHostname, CertificateHostnames $certificateHostnames): Response
    {
        // Only the edge may ask: anyone else could test which hostnames are customers, or have a shared
        // origin vouch for names on their say-so. Caddy's ask can't send headers, so its token comes in
        // the query (or as a bearer token). Strangers get the answer an unknown hostname gets.
        if (! $this->askedByEdge($request)) {
            return response('', 404);
        }

        $hostname = Str::lower(rtrim(trim((string) $request->query('domain')), '.'));

        if (Hostname::isValid($hostname) && $certificateHostnames->vouchFor($hostname)) {
            return response('', 200);
        }

        $domain = $resolveHostname->handle($hostname);

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
