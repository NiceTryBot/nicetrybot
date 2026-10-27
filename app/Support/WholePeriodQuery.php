<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a query that reads a whole period of requests (a count, a chart, a backtest) with Postgres told
 * to hash its lookups rather than loop over them. The planner can't size conditions like
 * `user_agents.value LIKE '%chrome/%'` (it guesses a handful of rows where there are thousands), so it
 * picks a nested loop and looks the lookups up once per request: millions of times over a week.
 * Hashing instead took a week-long backtest from 4.4s to 1.4s. The setting is local to a transaction
 * around the query, so nothing else on the connection is affected. Don't use it for a query that
 * stops early (the latest page of requests), where looping is the right plan. A query that groups
 * millions of rows can ask for hashed groupings too: the same misjudged sizes make the planner sort
 * them all to group them instead (a week-long backtest took 3.7s sorted, 1.8s hashed).
 */
class WholePeriodQuery
{
    /**
     * Postgres's code for a statement it cancelled, as when it ran past its statement_timeout.
     */
    private const string CANCELLED = '57014';

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $query
     * @param  float|null  $timeoutSeconds  Give up (with a QueryException that timedOut() recognizes) after this long.
     * @return TValue
     */
    public static function run(Closure $query, bool $hashAggregates = false, ?float $timeoutSeconds = null): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $query();
        }

        return DB::transaction(function () use ($query, $hashAggregates, $timeoutSeconds) {
            DB::statement('set local enable_nestloop = off');

            if ($hashAggregates) {
                DB::statement('set local enable_sort = off');
            }

            if ($timeoutSeconds !== null) {
                DB::statement('set local statement_timeout = '.max(1, (int) ($timeoutSeconds * 1000)));
            }

            return $query();
        });
    }

    /**
     * Determine if a query failed because it ran out of time.
     */
    public static function timedOut(Throwable $exception): bool
    {
        return $exception instanceof QueryException && (string) ($exception->errorInfo[0] ?? $exception->getCode()) === self::CANCELLED;
    }
}
