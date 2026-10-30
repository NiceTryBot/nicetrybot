<?php

namespace App\Http\Middleware;

use App\Rules\Hostname;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Take the site's hostname from X-Forwarded-Host only when the edge sends it. The edge shows gym pages
 * on the site's own domain and passes that domain along, with its token; everyone else's
 * X-Forwarded-Host is ignored (the trusted proxy headers leave it out), so nobody can make the app
 * build links, like a password reset email's, on a host of their choosing.
 */
class TrustEdgeForwardedHost
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->headers->get('X-Forwarded-Host');

        if (is_string($host) && AuthenticateEdge::isFromEdge($request)) {
            $host = strtolower(trim(explode(',', $host)[0]));

            if (Hostname::isValid($host)) {
                $request->headers->set('Host', $host);
            }
        }

        return $next($request);
    }
}
