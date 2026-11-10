<?php

namespace App\Actions\Analytics;

use App\Enums\ChartInterval;
use App\Enums\TrafficPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CountOverTime
{
    /**
     * Count a query's rows in bars of an interval across a period, by one timestamp column: from the bar
     * the period starts in to the one now is in. Each bar carries its start, end, label and count, the
     * shape the count chart draws. The Blackhole and Tarpit pages chart their lists this way.
     *
     * Pass $sumColumn to also total a numeric column per bar (the Tarpit page sums the time it held
     * clients, to tint each bar by it); each bar then carries that total as `weight`.
     *
     * @param  Builder  $query  A base query (pass an Eloquent builder's toBase()).
     * @param  literal-string  $column  The timestamp column to bucket by, table-qualified.
     * @param  literal-string|null  $sumColumn  A numeric column to total per bar, table-qualified.
     * @return array<int, array{key: string, label: string, start: int, end: int, count: int, weight?: int}>
     */
    public function handle(Builder $query, string $column, TrafficPeriod $period, ChartInterval $interval, ?CarbonImmutable $now = null, ?string $sumColumn = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $interval->floor($period->start($now));
        $count = intdiv($interval->floor($now)->getTimestamp() - $start->getTimestamp(), $interval->seconds()) + 1;
        $seconds = $interval->seconds();
        $offset = $interval->offsetSeconds();
        $epoch = $this->epochExpression($column);

        $weight = $sumColumn === null ? '' : ", coalesce(sum({$sumColumn}), 0) as weight";

        $rows = (clone $query)
            ->where($column, '>=', $start)
            ->selectRaw("{$epoch} - ({$epoch} - {$offset}) % {$seconds} as bucket, count(*) as total{$weight}")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $labelFormat = $interval->labelFormat($period);

        return collect(range(0, max(0, $count - 1)))
            ->map(function (int $index) use ($start, $seconds, $rows, $labelFormat, $sumColumn) {
                $bucketStart = $start->addSeconds($index * $seconds);
                $row = $rows->get($bucketStart->getTimestamp());

                return array_merge([
                    'key' => $bucketStart->format('Y-m-d H:i:s'),
                    'label' => $bucketStart->format($labelFormat),
                    'start' => $bucketStart->getTimestamp(),
                    'end' => $bucketStart->getTimestamp() + $seconds,
                    'count' => (int) ($row->total ?? 0),
                ], $sumColumn === null ? [] : ['weight' => (int) ($row->weight ?? 0)]);
            })
            ->all();
    }

    /**
     * The SQL expression for a column's whole-second Unix timestamp.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function epochExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "cast(unix_timestamp({$column}) as signed)",
            'pgsql' => "cast(floor(extract(epoch from {$column})) as bigint)",
            default => "cast(strftime('%s', {$column}) as integer)",
        };
    }
}
