<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdge
{
    /**
     * Let only the edge through: it sends the shared token from services.nicetry_edge.token as a
     * bearer token. With no token configured, nothing gets through.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::isFromEdge($request), 401);

        return $next($request);
    }

    /**
     * Determine if a request came through the edge, which vouches for its X-NiceTry-* headers.
     */
    public static function isFromEdge(Request $request): bool
    {
        $token = (string) config('services.nicetry_edge.token');

        return $token !== '' && hash_equals($token, (string) $request->bearerToken());
    }
}
