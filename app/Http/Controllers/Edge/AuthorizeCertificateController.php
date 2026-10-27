<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Domains\ResolveHostname;
use App\Http\Controllers\Controller;
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
        $domain = $resolveHostname->handle((string) $request->query('domain'));

        return response('', $domain ? 200 : 404);
    }
}
