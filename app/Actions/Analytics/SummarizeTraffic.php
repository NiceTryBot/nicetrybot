<?php

namespace App\Actions\Analytics;

use App\Data\TrafficSummary;
use App\Enums\ChartInterval;
use App\Enums\RequestAction;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\WhereCondition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class SummarizeTraffic
{
    /**
     * Summarize a team's edge traffic, optionally for a single domain.
     */
    public function handle(Team $team, TrafficPeriod $period, ?Domain $domain = null, ?CarbonImmutable $now = null): TrafficSummary
    {
        $now ??= CarbonImmutable::now();
        $start = $period->start($now);

        $current = $this->totals($this->query($team, $domain)->where('occurred_at', '>=', $start));
        $previous = $this->totals($this->query($team, $domain)
            ->where('occurred_at', '>=', $period->previousStart($now))
            ->where('occurred_at', '<', $start));

        return new TrafficSummary(
            period: $period,
            requests: $current['requests'],
            suspicious: $current['suspicious'],
            notFound: $current['not_found'],
            keptOffOrigin: $current['kept_off_origin'],
            gymMilliseconds: $current['gym_ms'],
            uniqueIps: $current['unique_ips'],
            previousRequests: $previous['requests'],
            previousSuspicious: $previous['suspicious'],
            previousNotFound: $previous['not_found'],
            previousKeptOffOrigin: $previous['kept_off_origin'],
            buckets: $this->buckets($this->query($team, $domain), $period, $start),
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
     * @param  Builder<EdgeRequest>  $query
     * @return array{requests: int, suspicious: int, not_found: int, kept_off_origin: int, gym_ms: int, unique_ips: int}
     */
    private function totals(Builder $query): array
    {
        $row = $query->toBase()->selectRaw(
            'count(*) as requests,
            sum(case when action <> ? then 1 else 0 end) as suspicious,
            sum(case when status_code = 404 then 1 else 0 end) as not_found,
            sum(case when action in (?, ?) then 1 else 0 end) as kept_off_origin,
            sum(case when action = ? then duration_ms else 0 end) as gym_ms,
            count(distinct client_ip) as unique_ips',
            [
                RequestAction::Passed->value,
                RequestAction::Diverted->value,
                RequestAction::Blocked->value,
                RequestAction::Diverted->value,
            ],
        )->first();

        return [
            'requests' => (int) $row->requests,
            'suspicious' => (int) $row->suspicious,
            'not_found' => (int) $row->not_found,
            'kept_off_origin' => (int) $row->kept_off_origin,
            'gym_ms' => (int) $row->gym_ms,
            'unique_ips' => (int) $row->unique_ips,
        ];
    }

    /**
     * Count any query's requests in the period's buckets, humans and suspicious apart, as the traffic
     * chart draws them.
     *
     * @param  Builder<EdgeRequest>|Relation<EdgeRequest, *, *>  $query
     * @return array<int, array{key: string, label: string, start: int, end: int, humans: int, suspicious: int, notFound: int}>
     */
    public function buckets(Builder|Relation $query, TrafficPeriod $period, CarbonImmutable $start): array
    {
        return $this->count($query, $start, $period->bucketSeconds(), 0, $period->bucketCount(), $period->bucketLabelFormat());
    }

    /**
     * Count any query's requests in bars of an interval across a period, humans and suspicious apart:
     * from the bar the period starts in to the one now is in. The Activity page charts its search
     * this way. Each condition in $counted adds a count, under its key, of the bar's requests it keeps.
     *
     * @param  Builder<EdgeRequest>|Relation<EdgeRequest, *, *>  $query
     * @param  array<string, WhereCondition>  $counted
     * @return array<int, array<string, int|string>>
     */
    public function bucketsBy(Builder|Relation $query, TrafficPeriod $period, ChartInterval $interval, ?CarbonImmutable $now = null, array $counted = []): array
    {
        $now ??= CarbonImmutable::now();
        $start = $interval->floor($period->start($now));
        $count = intdiv($interval->floor($now)->getTimestamp() - $start->getTimestamp(), $interval->seconds()) + 1;

        return $this->count($query, $start, $interval->seconds(), $interval->offsetSeconds(), $count, $interval->labelFormat($period), $counted);
    }

    /**
     * Count a query's requests from a start in buckets of so many seconds, lined up that many seconds
     * after the Unix epoch, and the requests each condition in $counted keeps.
     *
     * @param  Builder<EdgeRequest>|Relation<EdgeRequest, *, *>  $query
     * @param  array<string, WhereCondition>  $counted
     * @return array<int, array<string, int|string>>
     */
    private function count(Builder|Relation $query, CarbonImmutable $start, int $seconds, int $offset, int $count, string $labelFormat, array $counted = []): array
    {
        $epoch = $this->epochExpression();

        $counts = $query->toBase()
            ->where('edge_requests.occurred_at', '>=', $start)
            ->selectRaw(
                "{$epoch} - ({$epoch} - {$offset}) % {$seconds} as bucket,
                sum(case when edge_requests.action = ? then 1 else 0 end) as humans,
                sum(case when edge_requests.action <> ? then 1 else 0 end) as suspicious,
                sum(case when edge_requests.status_code = 404 then 1 else 0 end) as not_found",
                [RequestAction::Passed->value, RequestAction::Passed->value],
            )
            ->when($counted !== [], fn ($query) => $query->selectRaw(
                collect($counted)->map(fn (WhereCondition $condition, string $key) => $condition->countAs('counted_'.$key))->implode(', '),
                collect($counted)->flatMap(fn (WhereCondition $condition) => $condition->bindings)->all(),
            ))
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (object $row) => (int) $row->bucket);

        return collect(range(0, max(0, $count - 1)))
            ->map(function (int $offset) use ($start, $seconds, $counts, $labelFormat, $counted) {
                $bucketStart = $start->addSeconds($offset * $seconds);
                $row = $counts->get($bucketStart->getTimestamp());

                return [
                    'key' => $bucketStart->format('Y-m-d H:i:s'),
                    'label' => $bucketStart->format($labelFormat),
                    'start' => $bucketStart->getTimestamp(),
                    'end' => $bucketStart->getTimestamp() + $seconds,
                    'humans' => (int) ($row->humans ?? 0),
                    'suspicious' => (int) ($row->suspicious ?? 0),
                    'notFound' => (int) ($row->not_found ?? 0),
                    ...array_map(fn (string $key) => (int) ($row?->{'counted_'.$key} ?? 0), array_combine(array_keys($counted), array_keys($counted))),
                ];
            })
            ->all();
    }

    /**
     * Get the SQL expression for a request's whole-second Unix timestamp.
     *
     * @return literal-string
     */
    private function epochExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'cast(unix_timestamp(edge_requests.occurred_at) as signed)',
            'pgsql' => 'cast(floor(extract(epoch from edge_requests.occurred_at)) as bigint)',
            default => "cast(strftime('%s', edge_requests.occurred_at) as integer)",
        };
    }
}
