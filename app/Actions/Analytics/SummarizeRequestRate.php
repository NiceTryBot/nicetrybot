<?php

namespace App\Actions\Analytics;

use App\Data\RateSeries;
use App\Data\RequestRates;
use App\Enums\RequestAction;
use App\Enums\StatsRange;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SummarizeRequestRate
{
    /**
     * Chart a team's requests per second and per minute across the range, optionally for a single domain.
     */
    public function handle(Team $team, StatsRange $range, ?Domain $domain = null, ?CarbonImmutable $now = null): RequestRates
    {
        $now ??= CarbonImmutable::now();

        return new RequestRates(
            range: $range,
            perSecond: $this->series($this->query($team, $domain), $range, 1, $range->perSecondPeriod(), $now),
            perMinute: $this->series($this->query($team, $domain), $range, 60, $range->perMinutePeriod(), $now),
            lastMinute: $this->query($team, $domain)->where('occurred_at', '>', $now->subMinute())->where('occurred_at', '<=', $now)->count(),
            requests: $this->query($team, $domain)->where('occurred_at', '>=', $now->subSeconds($range->seconds()))->where('occurred_at', '<=', $now)->count(),
        );
    }

    /**
     * @return Builder<EdgeRequest>
     */
    private function query(Team $team, ?Domain $domain): Builder
    {
        return EdgeRequest::query()
            ->whereIn('domain_id', $team->domains()->select('id'))
            ->when($domain, fn (Builder $query) => $query->where('domain_id', $domain->id));
    }

    /**
     * Count requests per unit, then roll the units up into one point per period: the average rate across
     * the period and its busiest single unit. Only whole periods are charted, so the newest point never
     * dips just because its period is still under way.
     *
     * @param  Builder<EdgeRequest>  $query
     */
    private function series(Builder $query, StatsRange $range, int $unit, int $period, CarbonImmutable $now): RateSeries
    {
        $last = intdiv($now->getTimestamp(), $period) * $period - $period;
        $first = $last - intdiv($range->seconds(), $period) * $period + $period;
        $epoch = $this->epochExpression();

        $units = $query->toBase()
            ->where('occurred_at', '>=', CarbonImmutable::createFromTimestamp($first))
            ->where('occurred_at', '<', CarbonImmutable::createFromTimestamp($last + $period))
            ->selectRaw(
                "{$epoch} - {$epoch} % {$unit} as tick, count(*) as requests, sum(case when action <> ? then 1 else 0 end) as suspicious",
                [RequestAction::Passed->value],
            )
            ->groupBy('tick');

        $counts = DB::query()
            ->fromSub($units, 'units')
            ->selectRaw("tick - tick % {$period} as bucket, sum(requests) as requests, sum(suspicious) as suspicious, max(requests) as peak")
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (object $row) => (int) $row->bucket);

        $unitsPerPeriod = $period / $unit;
        $labelFormat = $period < 60 ? 'M j, H:i:s' : 'M j, H:i';

        $points = [];

        for ($bucket = $first; $bucket <= $last; $bucket += $period) {
            $row = $counts->get($bucket);

            $points[] = [
                't' => $bucket,
                'label' => CarbonImmutable::createFromTimestamp($bucket)->format($labelFormat),
                'requests' => round((int) ($row->requests ?? 0) / $unitsPerPeriod, 2),
                'suspicious' => round((int) ($row->suspicious ?? 0) / $unitsPerPeriod, 2),
                'peak' => (int) ($row->peak ?? 0),
            ];
        }

        return new RateSeries($unit, $period, $points);
    }

    /**
     * Get the SQL expression for a request's whole-second Unix timestamp.
     *
     * @return literal-string
     */
    private function epochExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'cast(unix_timestamp(occurred_at) as signed)',
            'pgsql' => 'cast(floor(extract(epoch from occurred_at)) as bigint)',
            default => "cast(strftime('%s', occurred_at) as integer)",
        };
    }
}
