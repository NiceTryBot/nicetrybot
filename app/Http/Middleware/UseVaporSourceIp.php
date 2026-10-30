<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On Vapor, believe only the visitor's IP that Vapor's runtime saw. Vapor trusts every proxy (it can't
 * know API Gateway's addresses), so X-Forwarded-For, which anyone can send, would otherwise decide
 * $request->ip(): and with it login throttling. The runtime's X-Vapor-Source-Ip replaces it.
 * Anywhere else this does nothing: that header is just something a visitor could send.
 */
class UseVaporSourceIp
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (getenv('LAMBDA_TASK_ROOT') !== false) {
            $sourceIp = $request->header('X-Vapor-Source-Ip');

            if (is_string($sourceIp) && filter_var($sourceIp, FILTER_VALIDATE_IP) !== false) {
                $request->headers->set('X-Forwarded-For', $sourceIp);
            } else {
                $request->headers->remove('X-Forwarded-For');
            }
        }

        return $next($request);
    }
}
