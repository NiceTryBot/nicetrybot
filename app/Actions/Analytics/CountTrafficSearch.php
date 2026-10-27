<?php

namespace App\Actions\Analytics;

use App\Actions\Rules\BuildRuleQuery;
use App\Data\RequestQuery;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ChartInterval;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\RuleField;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\WholePeriodQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Count an Activity search's requests over a period, as an agent asks about a team's traffic: the
 * totals, the busiest values of one field, or the requests in bars of time. The search goes through the
 * rule language's query builder, which binds every value and scopes it to the team, and every count is
 * bounded by the period's start and a statement timeout: edge_requests is far too big to read whole.
 */
class CountTrafficSearch
{
    /**
     * How long one count may take before Postgres gives up on it.
     */
    public const float TIMEOUT_SECONDS = 20.0;

    /**
     * The fields a search's requests can be grouped by: the core's, apart from the response time (no two
     * requests share one). Add-on fields aren't offered, since their joins could clash with the search's.
     *
     * @var list<string>
     */
    public const array GROUPABLE = [
        'host', 'path', 'category', 'status', 'method', 'action', 'tag', 'client',
        'ip', 'ua', 'ja4', 'ja4.alpn', 'ja3', 'ja4h', 'hints', 'lang',
        'asn', 'network', 'network.kind', 'country', 'region', 'city',
    ];

    public function __construct(private BuildRuleQuery $buildRuleQuery)
    {
        //
    }

    /**
     * Count the search's requests and the distinct IPs they came from.
     *
     * @return array{requests: int, unique_ips: int}
     *
     * @throws RuleScriptSyntaxError
     */
    public function totals(Team $team, RequestQuery $search, TrafficPeriod $period, ?CarbonImmutable $now = null): array
    {
        $query = $this->requests($team, $search, $period, $now)->toBase()
            ->selectRaw('count(*) as requests, count(distinct edge_requests.client_ip) as unique_ips');

        $row = WholePeriodQuery::run(fn () => $query->first(), timeoutSeconds: self::TIMEOUT_SECONDS);

        return ['requests' => (int) ($row->requests ?? 0), 'unique_ips' => (int) ($row->unique_ips ?? 0)];
    }

    /**
     * Count the search's requests by the values of one field, busiest first. Each row's value is the
     * literal a search writes for it, and its search narrows the search to that value.
     *
     * @return list<array{value: int|string|null, label: string, requests: int, unique_ips: int, search: string}>
     *
     * @throws RuleScriptSyntaxError
     */
    public function groups(Team $team, RequestQuery $search, TrafficPeriod $period, RuleField $field, int $limit, ?CarbonImmutable $now = null): array
    {
        if ($field === RuleField::Client) {
            return $this->clientGroups($team, $search, $period, $now);
        }

        $column = $field === RuleField::Asn ? 'edge_requests.asn' : $field->column();
        $grouping = $field === RuleField::Asn ? "{$column}, networks.name" : $column;

        $query = $this->requests($team, $search, $period, $now)->toBase()
            ->selectRaw("{$column} as value".($field === RuleField::Asn ? ', networks.name as network_name' : '').', count(*) as requests, count(distinct edge_requests.client_ip) as unique_ips')
            ->groupByRaw($grouping)
            ->orderByDesc('requests')
            ->orderBy('value')
            ->limit($limit);

        return array_values(WholePeriodQuery::run(fn () => $query->get(), hashAggregates: true, timeoutSeconds: self::TIMEOUT_SECONDS)
            ->map(function (stdClass $row) use ($field, $search) {
                [$value, $label] = $this->literal($field, $row);

                return [
                    'value' => $value,
                    'label' => $label,
                    'requests' => (int) $row->requests,
                    'unique_ips' => (int) $row->unique_ips,
                    'search' => $search->with($value === null ? "{$field->value} IS NULL" : $field->value.' = '.(is_int($value) ? $value : RequestQuery::quote($value)))->source,
                ];
            })
            ->all());
    }

    /**
     * Count the search's requests in bars of an interval across the period, oldest first.
     *
     * @return list<array{start: string, label: string, requests: int, passed: int, suspicious: int}>
     *
     * @throws RuleScriptSyntaxError
     */
    public function buckets(Team $team, RequestQuery $search, TrafficPeriod $period, ChartInterval $interval, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $query = $this->requests($team, $search, $period, $now);

        $buckets = WholePeriodQuery::run(fn () => app(SummarizeTraffic::class)->bucketsBy($query, $period, $interval, $now), hashAggregates: true, timeoutSeconds: self::TIMEOUT_SECONDS);

        return array_map(fn (array $bucket) => [
            'start' => CarbonImmutable::createFromTimestampUTC((int) $bucket['start'])->toIso8601ZuluString(),
            'label' => (string) $bucket['label'],
            'requests' => (int) $bucket['humans'] + (int) $bucket['suspicious'],
            'passed' => (int) $bucket['humans'],
            'suspicious' => (int) $bucket['suspicious'],
        ], array_values($buckets));
    }

    /**
     * Start the query for the search's requests over the period, with the lookups joined: its WHERE
     * applied in place, and its HAVING keeping the requests of the clients whose requests pass it, as the Activity page does.
     *
     * @return HasManyThrough<EdgeRequest, Domain, Team>
     *
     * @throws RuleScriptSyntaxError
     */
    public function requests(Team $team, RequestQuery $search, TrafficPeriod $period, ?CarbonImmutable $now = null): HasManyThrough
    {
        $parsed = $search->parse();
        $start = $period->start($now);
        $subjects = $search->subjects();

        $requests = $team->edgeRequests()->where('edge_requests.occurred_at', '>=', $start);

        // Joined even without a WHERE, so a grouping can read the lookups.
        $this->buildRuleQuery->constrain($requests->getQuery()->getQuery(), $team, $parsed['where']);

        if ($parsed['having'] !== null) {
            $requests->whereIn(
                DB::raw(BuildRuleQuery::clientKey($subjects)),
                $this->buildRuleQuery->clients($team, $parsed['where'], $subjects, $parsed['having'])
                    ->where('edge_requests.occurred_at', '>=', $start)
                    ->selectRaw(BuildRuleQuery::clientKey($subjects)),
            );
        }

        return $requests;
    }

    /**
     * Count the search's requests by the kinds of client they came from, plus those from none of them.
     * A request can come from a client of several kinds, so the rows can add up to more than the total.
     *
     * @return list<array{value: string|null, label: string, requests: int, unique_ips: int, search: string}>
     */
    private function clientGroups(Team $team, RequestQuery $search, TrafficPeriod $period, ?CarbonImmutable $now): array
    {
        $kinds = [
            ...array_map(fn (string $kind) => ['value' => $kind, 'label' => $kind, 'clause' => 'client = '.RequestQuery::quote($kind)], RuleField::CLIENT_VALUES),
            ['value' => null, 'label' => 'none of these', 'clause' => 'client NOT IN ('.implode(', ', array_map(fn (string $kind) => RequestQuery::quote($kind), RuleField::CLIENT_VALUES)).')'],
        ];

        $selects = [];
        $bindings = [];

        foreach ($kinds as $index => $kind) {
            [$sql, $conditionBindings] = $this->buildRuleQuery->condition((new RequestQuery($kind['clause']))->where(), $team->id);
            $selects[] = "sum(case when {$sql} then 1 else 0 end) as requests_{$index}, count(distinct case when {$sql} then edge_requests.client_ip end) as unique_ips_{$index}";
            $bindings = [...$bindings, ...$conditionBindings, ...$conditionBindings];
        }

        $query = $this->requests($team, $search, $period, $now)->toBase()->selectRaw(implode(', ', $selects), $bindings);
        $row = WholePeriodQuery::run(fn () => $query->first(), timeoutSeconds: self::TIMEOUT_SECONDS);

        return array_values(collect($kinds)
            ->map(fn (array $kind, int $index) => [
                'value' => $kind['value'],
                'label' => $kind['label'],
                'requests' => (int) ($row->{"requests_{$index}"} ?? 0),
                'unique_ips' => (int) ($row->{"unique_ips_{$index}"} ?? 0),
                'search' => $search->with($kind['clause'])->source,
            ])
            ->sortByDesc('requests')
            ->all());
    }

    /**
     * Turn a grouped row's stored value into the literal a search writes for it, and a label to show.
     *
     * @return array{0: int|string|null, 1: string}
     */
    private function literal(RuleField $field, stdClass $row): array
    {
        $stored = $row->value;

        if ($stored === null) {
            return [null, $field === RuleField::Category ? 'none (an ordinary page)' : 'none'];
        }

        $value = match ($field) {
            RuleField::Category => PathCategory::tryFrom((int) $stored)?->slug(),
            RuleField::Action => RequestAction::tryFrom((int) $stored)?->slug(),
            RuleField::Method => HttpMethod::tryFrom((int) $stored)?->label(),
            RuleField::NetworkKind => NetworkKind::tryFrom((int) $stored) === NetworkKind::Hosting ? 'hosting' : 'unknown',
            RuleField::Status, RuleField::Asn => (int) $stored,
            default => (string) $stored,
        } ?? (string) $stored;

        $label = match ($field) {
            RuleField::Asn => trim("AS{$value} ".($row->network_name ?? '')),
            RuleField::Action => RequestAction::fromSlug((string) $value)?->label() ?? (string) $value,
            default => (string) $value,
        };

        return [$value, $label];
    }
}
