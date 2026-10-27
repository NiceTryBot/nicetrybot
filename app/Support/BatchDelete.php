<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Delete the rows a query finds a batch at a time, so a large delete never holds one huge transaction
 * or lock, and can stop at a deadline and carry on next time.
 */
class BatchDelete
{
    /**
     * Rows deleted per statement.
     */
    public const int BATCH_SIZE = 5000;

    /**
     * Delete the rows of a table whose ids the query selects (as `id`), until there are none left or
     * the deadline (a microtime) passes. Returns how many were deleted.
     */
    public static function run(string $table, Builder $ids, float $deadline = INF): int
    {
        $deleted = 0;

        do {
            $batch = (clone $ids)->limit(self::BATCH_SIZE)->pluck('id')->all();

            if ($batch === []) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $batch)->delete();
        } while (count($batch) === self::BATCH_SIZE && microtime(true) < $deadline);

        return $deleted;
    }
}
