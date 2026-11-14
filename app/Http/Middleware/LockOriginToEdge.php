<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve this panel only through the edge, when it sits behind one (nicetry.edge_only_hosts): a request
 * must ask for one of those hostnames and carry that domain's X-NiceTry-Origin-Secret, which only the
 * edge adds. Anything else (the origin's own hostname, the CloudFront address, a forged Host) gets a
 * 404. The edge's own calls, with its token, pass as before.
 *
 * The edge reaches the origin on the origin's hostname (CloudFront answers no other), so the panel's
 * hostname comes from X-Forwarded-Host and the visitor from the first X-Forwarded-For, which the edge
 * sets itself: on Vapor that replaces X-Vapor-Source-Ip, the edge's own address.
 */
class LockOriginToEdge
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hostnames = self::hostnames();

        if ($hostnames === [] || AuthenticateEdge::isFromEdge($request)) {
            return $next($request);
        }

        $hostname = strtolower(trim(explode(',', (string) ($request->headers->get('X-Forwarded-Host') ?? $request->getHost()))[0]));
        $secret = (string) $request->headers->get('X-NiceTry-Origin-Secret');

        if (! in_array($hostname, $hostnames, true) || ! self::isOriginSecret($hostname, $secret)) {
            abort(404);
        }

        $request->headers->set('Host', $hostname);
        $request->headers->remove('X-NiceTry-Origin-Secret');

        $visitorIp = trim(explode(',', (string) $request->headers->get('X-Forwarded-For'))[0]);

        if (filter_var($visitorIp, FILTER_VALIDATE_IP) !== false) {
            $request->headers->set('X-Forwarded-For', $visitorIp);
            $request->headers->set('X-Vapor-Source-Ip', $visitorIp);
        }

        return $next($request);
    }

    /**
     * Get the hostnames the panel answers on, only through the edge.
     *
     * @return list<string>
     */
    public static function hostnames(): array
    {
        return array_values(array_filter(array_map(
            fn (string $hostname): string => strtolower(trim($hostname)),
            explode(',', (string) config('nicetry.edge_only_hosts')),
        )));
    }

    /**
     * Determine if the secret is the domain's origin secret. It's cached; a miss reads the domain again,
     * so a rotated secret works as soon as the edge sends it.
     */
    private static function isOriginSecret(string $hostname, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $key = "edge-only:origin-secret:{$hostname}";
        $cached = Cache::get($key);

        if (is_string($cached) && hash_equals($cached, $secret)) {
            return true;
        }

        $current = Domain::query()->where('hostname', $hostname)->value('origin_secret');

        if (! is_string($current) || $current === '') {
            return false;
        }

        Cache::put($key, $current, now()->addHour());

        return hash_equals($current, $secret);
    }
}
