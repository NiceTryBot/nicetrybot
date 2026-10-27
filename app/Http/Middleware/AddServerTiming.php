<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Says how long each response took and how much of that was the database, in a Server-Timing header,
 * so the browser's network panel shows where a slow page or Livewire update spends its time.
 */
class AddServerTiming
{
    private static float $databaseMilliseconds = 0;

    private static int $queries = 0;

    /**
     * Count a query the database ran (called for every query, from AppServiceProvider).
     */
    public static function recordQuery(float $milliseconds): void
    {
        self::$databaseMilliseconds += $milliseconds;
        self::$queries++;
    }

    /**
     * Leave out the queries run so far, for a response that must look like one that never touched the
     * database (the API's 404 for a bad token, which a made-up path answers without a query).
     */
    public static function forgetQueries(): void
    {
        self::$databaseMilliseconds = 0;
        self::$queries = 0;
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$databaseMilliseconds = 0;
        self::$queries = 0;
        $startedAt = hrtime(true);

        $response = $next($request);

        $response->headers->set('Server-Timing', sprintf(
            'db;dur=%.1f;desc="%d queries", app;dur=%.1f',
            self::$databaseMilliseconds,
            self::$queries,
            (hrtime(true) - $startedAt) / 1e6,
        ));

        return $response;
    }
}
