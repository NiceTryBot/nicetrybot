<?php

namespace App\Actions\Rules;

use App\Data\RuleBacktest;
use App\Data\RuleScript;
use App\Enums\NetworkKind;
use App\Enums\RuleSubject;
use App\Models\Team;
use App\Support\WholePeriodQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BacktestRule
{
    /**
     * The most matching requests a windowed (HAVING) backtest reads.
     */
    private const int WINDOWED_ROW_LIMIT = 50_000;

    public function __construct(private BuildRuleQuery $buildRuleQuery)
    {
        //
    }

    /**
     * Show which subjects a script would have matched over the last few days, busiest first. A plain rule
     * counts every matching request, grouped by subject in the database. A HAVING rule is replayed by
     * sliding its window over each subject's requests (the first WINDOWED_ROW_LIMIT of them), so a burst
     * anywhere in the period counts, as it would have live.
     */
    public function handle(Team $team, RuleScript $script, int $days = 7, int $limit = 12, ?CarbonImmutable $now = null): RuleBacktest
    {
        $now ??= CarbonImmutable::now();
        $query = $this->buildRuleQuery->handle($team, $script)->where('edge_requests.occurred_at', '>=', $now->subDays($days));

        $subjects = ($script->isAggregate() ? $this->replayedSubjects($script, $query) : $this->groupedSubjects($script, $query))
            ->sort(fn (object $a, object $b) => $b->requests <=> $a->requests ?: strcmp($a->first_seen, $b->first_seen))
            ->values();
        $top = $subjects->take($limit);

        // Where the busiest were last seen from: their latest request's network and location.
        $latest = DB::table('edge_requests')->whereIn('id', $top->pluck('last_id')->filter()->values())->get(['id', 'asn', 'location_id'])->keyBy('id');
        $networks = DB::table('networks')->whereIn('asn', $latest->pluck('asn')->filter()->unique()->values())->pluck('name', 'asn');
        $countries = DB::table('locations')->whereIn('id', $latest->pluck('location_id')->filter()->unique()->values())->pluck('country_code', 'id');
        $ja4s = DB::table('tls_fingerprints')->whereIn('id', $top->pluck('subject_ja4')->filter()->unique()->values())->pluck('value', 'id');

        return new RuleBacktest(
            days: $days,
            subjectCount: $subjects->count(),
            requestCount: $subjects->sum('requests'),
            outsideHostingCount: $subjects->reject(fn (object $subject) => $subject->hosting)->count(),
            subjects: $top->map(fn (object $subject) => [
                'client_ip' => $subject->subject_ip,
                'ja4' => $subject->subject_ja4 === null ? null : $ja4s->get($subject->subject_ja4),
                'network' => $networks->get($latest->get($subject->last_id)?->asn),
                'is_hosting' => $subject->hosting,
                'country' => $countries->get($latest->get($subject->last_id)?->location_id),
                'requests' => $subject->requests,
                'hosts' => $subject->hosts,
                'first_seen' => $subject->first_seen,
                'last_seen' => $subject->last_seen,
            ])->values()->all(),
        );
    }

    /**
     * Count a plain rule's matches per subject in the database: every matching request in the period,
     * not a sample. Grouped by subject and domain first, then by subject, so the distinct domains come
     * from two hashed groupings rather than a sort of millions of rows. A subject is on hosting when
     * every one of its requests came from a hosting network.
     *
     * @return Collection<int, object{subject_ip: ?string, subject_ja4: ?int, requests: int, hosts: int, first_seen: string, last_seen: string, last_id: int, hosting: bool}>
     */
    private function groupedSubjects(RuleScript $script, Builder $query): Collection
    {
        $columns = array_filter([
            'subject_ip' => in_array(RuleSubject::Ip, $script->subjects, true) ? 'edge_requests.client_ip' : null,
            'subject_ja4' => in_array(RuleSubject::Ja4, $script->subjects, true) ? 'edge_requests.ja4_id' : null,
        ]);

        $perDomain = $query
            ->selectRaw(implode(', ', [
                ($columns['subject_ip'] ?? 'null').' as subject_ip',
                ($columns['subject_ja4'] ?? 'null').' as subject_ja4',
                'edge_requests.domain_id as domain_id',
                'count(*) as requests',
                'min(edge_requests.occurred_at) as first_seen',
                'max(edge_requests.occurred_at) as last_seen',
                'max(edge_requests.id) as last_id',
                'min(case when networks.kind = ? then 1 else 0 end) as hosting',
            ]), [NetworkKind::Hosting->value])
            ->groupByRaw(implode(', ', [...array_values($columns), 'edge_requests.domain_id']));

        $subjects = WholePeriodQuery::run(fn () => DB::query()->fromSub($perDomain, 'per_domain')
            ->selectRaw('subject_ip, subject_ja4, sum(requests) as requests, count(*) as hosts, min(first_seen) as first_seen, max(last_seen) as last_seen, max(last_id) as last_id, min(hosting) as hosting')
            ->groupBy('subject_ip', 'subject_ja4')
            ->get(), hashAggregates: true);

        return $subjects->map(fn (object $subject) => (object) [
            'subject_ip' => $subject->subject_ip,
            'subject_ja4' => $subject->subject_ja4 === null ? null : (int) $subject->subject_ja4,
            'requests' => (int) $subject->requests,
            'hosts' => (int) $subject->hosts,
            'first_seen' => (string) $subject->first_seen,
            'last_seen' => (string) $subject->last_seen,
            'last_id' => (int) $subject->last_id,
            'hosting' => (bool) $subject->hosting,
        ]);
    }

    /**
     * Replay a HAVING rule over each subject's requests in time order, keeping the subjects where HAVING
     * held in some window.
     *
     * @return Collection<int, object{subject_ip: ?string, subject_ja4: ?int, requests: int, hosts: int, first_seen: string, last_seen: string, last_id: int, hosting: bool}>
     */
    private function replayedSubjects(RuleScript $script, Builder $query): Collection
    {
        [$filters, $filterBindings] = $this->filterColumns($script);

        $rows = WholePeriodQuery::run(fn () => $query->selectRaw(implode(', ', [
            in_array(RuleSubject::Ip, $script->subjects, true) ? 'edge_requests.client_ip as subject_ip' : 'null as subject_ip',
            in_array(RuleSubject::Ja4, $script->subjects, true) ? 'edge_requests.ja4_id as subject_ja4' : 'null as subject_ja4',
            'edge_requests.id as id',
            'edge_requests.occurred_at as occurred_at',
            'networks.kind as network_kind',
            'domains.hostname as host',
            ...array_map(fn ($field) => $field->column().' as '.$this->alias($field->value), $script->fields()),
            ...$filters,
        ]), $filterBindings)->orderBy('edge_requests.occurred_at')->limit(self::WINDOWED_ROW_LIMIT)->get());

        return $rows->groupBy(fn (object $row) => $row->subject_ip.'|'.$row->subject_ja4)
            ->filter(fn (Collection $requests) => $this->anyWindowMatches($script, $requests))
            ->map(fn (Collection $requests) => (object) [
                'subject_ip' => $requests->first()->subject_ip,
                'subject_ja4' => $requests->first()->subject_ja4 === null ? null : (int) $requests->first()->subject_ja4,
                'requests' => $requests->count(),
                'hosts' => $requests->pluck('host')->unique()->count(),
                'first_seen' => (string) $requests->first()->occurred_at,
                'last_seen' => (string) $requests->last()->occurred_at,
                'last_id' => (int) $requests->last()->id,
                'hosting' => $requests->every(fn (object $request) => $request->network_kind !== null && (int) $request->network_kind === NetworkKind::Hosting->value),
            ])
            ->values();
    }

    /**
     * Determine if a script matches one subject's requests between two moments: any request passing
     * WHERE, or, for a HAVING rule, a window over them where HAVING holds. A null IP or fingerprint
     * leaves that part of the subject open.
     */
    public function subjectMatches(Team $team, RuleScript $script, ?string $clientIp, ?int $ja4Id, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $query = $this->buildRuleQuery->handle($team, $script)
            ->whereBetween('edge_requests.occurred_at', [$from, $to])
            ->when($clientIp !== null, fn ($query) => $query->where('edge_requests.client_ip', $clientIp))
            ->when($ja4Id !== null, fn ($query) => $query->where('edge_requests.ja4_id', $ja4Id));

        if (! $script->isAggregate()) {
            return $query->exists();
        }

        [$filters, $filterBindings] = $this->filterColumns($script);

        $requests = $query->selectRaw(implode(', ', [
            'edge_requests.occurred_at as occurred_at',
            'domains.hostname as host',
            ...array_map(fn ($field) => $field->column().' as '.$this->alias($field->value), $script->fields()),
            ...$filters,
        ]), $filterBindings)->orderBy('edge_requests.occurred_at')->limit(self::WINDOWED_ROW_LIMIT)->get();

        return $requests->isNotEmpty() && $this->anyWindowMatches($script, $requests);
    }

    /**
     * Slide the rule's window over one subject's requests (in time order) and report whether HAVING
     * held in any window.
     *
     * @param  Collection<int, object>  $requests
     */
    private function anyWindowMatches(RuleScript $script, Collection $requests): bool
    {
        $requests = $requests->values();
        $start = 0;

        foreach ($requests as $end => $request) {
            $windowEnd = CarbonImmutable::parse($request->occurred_at)->getTimestamp();

            while (CarbonImmutable::parse($requests[$start]->occurred_at)->getTimestamp() < $windowEnd - $script->windowSeconds) {
                $start++;
            }

            if ($this->holds($script->having, $requests->slice($start, $end - $start + 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a HAVING expression against the requests in one window.
     *
     * @param  array<string, mixed>  $node
     * @param  Collection<int, object>  $window
     */
    private function holds(array $node, Collection $window): bool
    {
        return match ($node['kind']) {
            'and' => $this->holds($node['left'], $window) && $this->holds($node['right'], $window),
            'or' => $this->holds($node['left'], $window) || $this->holds($node['right'], $window),
            'not' => ! $this->holds($node['expr'], $window),
            'aggregate' => $this->compare($this->count($node, $window), $node['operator'], $node['value']),
        };
    }

    /**
     * Count a window's requests as an aggregate does: those its FILTER keeps, or every distinct value of
     * a field among them.
     *
     * @param  array<string, mixed>  $node
     * @param  Collection<int, object>  $window
     */
    private function count(array $node, Collection $window): int
    {
        if (($node['filter_index'] ?? null) !== null) {
            $window = $window->filter(fn (object $request) => (bool) $request->{'having_filter_'.$node['filter_index']});
        }

        return $node['distinct'] === null
            ? $window->count()
            : $window->pluck($this->alias($node['distinct']->value))->filter(fn ($value) => $value !== null)->unique()->count();
    }

    /**
     * Select whether each request passes each of HAVING's FILTER conditions, so a window can count them.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filterColumns(RuleScript $script): array
    {
        $columns = [];
        $bindings = [];

        foreach ($script->having === null ? [] : $this->buildRuleQuery->filters($script->having) as $index => [$condition, $conditionBindings]) {
            $columns[] = "case when {$condition} then 1 else 0 end as having_filter_{$index}";
            $bindings = [...$bindings, ...$conditionBindings];
        }

        return [$columns, $bindings];
    }

    private function compare(int $actual, string $operator, int $expected): bool
    {
        return match ($operator) {
            '=' => $actual === $expected,
            '!=' => $actual !== $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
        };
    }

    /**
     * Get the select alias used for a field's value.
     */
    private function alias(string $field): string
    {
        return 'field_'.str_replace('.', '_', $field);
    }
}
