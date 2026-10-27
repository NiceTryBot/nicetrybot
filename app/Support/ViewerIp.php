<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The IP the person using the app is browsing from. On Vapor a request reaches PHP from API Gateway, so
 * the remote address is 127.0.0.1; the runtime passes the real one along as X-Vapor-Source-Ip. Anywhere
 * else (a self-hosted panel behind its edge) that header is just something a visitor could send, so it's
 * only believed on Lambda, where Vapor's runtime sets it.
 */
final class ViewerIp
{
    public static function of(Request $request): ?string
    {
        $vapor = getenv('LAMBDA_TASK_ROOT') !== false ? $request->header('X-Vapor-Source-Ip') : null;

        foreach ([$vapor, $request->ip()] as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false && ! in_array($candidate, ['127.0.0.1', '::1'], true)) {
                return $candidate;
            }
        }

        return null;
    }
}
