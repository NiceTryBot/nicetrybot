<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gzip what the edge polls, when it accepts gzip (Go's HTTP client always does, and unpacks it
 * itself). The gym triggers list names every enforcing site's hostnames for each routine, which for a
 * team with thousands of sites passed the 6 MB a Lambda may answer with: API Gateway turned it into a
 * 502, and the edges kept polling an old list. Hostnames shrink about tenfold. ETags and 304s still
 * come from the plain JSON, since this wraps the cache headers middleware.
 */
class CompressEdgeFeeds
{
    /**
     * Below this, compressing isn't worth it.
     */
    private const int MIN_BYTES = 1024;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $body = $response->getContent();

        if ($response->getStatusCode() !== 200
            || ! is_string($body) || strlen($body) < self::MIN_BYTES
            || $response->headers->has('Content-Encoding')
            || ! str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')) {
            return $response;
        }

        $response->setContent((string) gzencode($body, 6));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding', false);
        $response->headers->remove('Content-Length');

        // Vapor passes a binary body through API Gateway only when asked to base64 it.
        if (getenv('LAMBDA_TASK_ROOT') !== false) {
            $response->headers->set('X-Vapor-Base64-Encode', 'True');
        }

        return $response;
    }
}
