<?php

namespace App\Mcp\Tools\Traffic;

use App\Actions\Analytics\CountTrafficSearch;
use App\Data\RequestQuery;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ChartInterval;
use App\Enums\RuleField;
use App\Enums\TrafficPeriod;
use App\Mcp\Support\TrafficChart;
use App\Mcp\Tools\TeamTool;
use App\Support\WholePeriodQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Count this team's requests that match a search over a recent period, optionally broken down by a
    field or over time, and optionally drawn as a PNG chart. Call describe-traffic-fields first: the
    where is NiceTryBot's rule language (like client = 'spoofed' AND category = 'probe/creds'), not SQL.
    Returns the totals (requests, and unique_ips: the distinct IPs, which is what "clients" usually
    means), then rows busiest first (each with a search that narrows to it, to query again) or time
    buckets, and an activity_url that opens the same search on the Activity page. Share that link
    when the person wants to look at the requests themselves.
    TEXT)]
#[IsReadOnly]
class QueryTraffic extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CountTrafficSearch $countTraffic, TrafficChart $chart): Response|ResponseFactory
    {
        $validated = $request->validate([
            'where' => ['nullable', 'string', 'max:4000'],
            'period' => ['nullable', Rule::enum(TrafficPeriod::class)],
            'group_by' => ['nullable', 'string', Rule::in([...CountTrafficSearch::GROUPABLE, 'time'])],
            'interval' => ['nullable', Rule::enum(ChartInterval::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'chart' => ['nullable', 'boolean'],
        ], [
            'group_by.in' => 'group_by is one of: '.implode(', ', [...CountTrafficSearch::GROUPABLE, 'time']).'.',
        ]);

        $search = new RequestQuery(trim((string) ($validated['where'] ?? '')));
        $period = TrafficPeriod::from($validated['period'] ?? TrafficPeriod::Day->value);
        $groupBy = $validated['group_by'] ?? null;
        $interval = $groupBy === 'time' ? $this->interval($validated['interval'] ?? null, $period) : null;

        if (is_string($interval)) {
            return Response::error($interval);
        }

        try {
            $readsAs = $search->describe();
        } catch (RuleScriptSyntaxError $error) {
            return Response::error("The where doesn't parse: {$error->getMessage()} (line {$error->scriptLine}, column {$error->scriptColumn}). Fix it and call query-traffic again; describe-traffic-fields has the grammar and the fields.");
        }

        $buckets = null;
        $rows = null;

        try {
            $totals = $countTraffic->totals($this->team(), $search, $period);
            $data = [
                'where' => $search->source,
                'reads_as' => $readsAs,
                'period' => $period->value,
                'period_label' => $period->label(),
                'since' => $period->start()->toIso8601ZuluString(),
                'totals' => $totals,
                'group_by' => $groupBy,
            ];

            if ($interval instanceof ChartInterval) {
                $buckets = $countTraffic->buckets($this->team(), $search, $period, $interval);
                $data['interval'] = $interval->value;
                $data['buckets'] = $buckets;
            } elseif ($groupBy !== null) {
                $rows = $countTraffic->groups($this->team(), $search, $period, RuleField::from($groupBy), (int) ($validated['limit'] ?? 20));
                $data['rows'] = $rows;
            }
        } catch (QueryException $exception) {
            if (! WholePeriodQuery::timedOut($exception)) {
                throw $exception;
            }

            return Response::error("Counting that search over the {$period->label()} took too long. Try a shorter period or a narrower where (a host, a path or a category).");
        }

        $data['activity_url'] = route('activity.index', array_filter([
            'current_team' => $this->team()->slug,
            'q' => $search->isBlank() ? null : $search->source,
            'period' => $period === TrafficPeriod::Day ? null : $period->value,
            'by' => $interval?->value,
        ]));

        if (! ($validated['chart'] ?? false)) {
            return Response::structured($data);
        }

        return Response::make([Response::json($data), Response::image($this->draw($chart, $readsAs, $period, $totals, $buckets, $rows, $interval, $groupBy))])->withStructuredContent($data);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'where' => $schema->string()->description('The search, in NiceTryBot\'s rule language (see describe-traffic-fields), like host LIKE \'%shop%\' AND status = 404. Leave it out to count every request.'),
            'period' => $schema->string()->enum(array_column(TrafficPeriod::cases(), 'value'))->description('How far back to count, from now. Default 24h. Pick the smallest that covers the question: long periods over broad searches can take too long.'),
            'group_by' => $schema->string()->enum([...CountTrafficSearch::GROUPABLE, 'time'])->description('Optional: break the counts down by a field\'s values (busiest first), or by time for requests per interval. "client" counts spoofed, outdated and corporate clients, and those that are none of them.'),
            'interval' => $schema->string()->enum(array_column(ChartInterval::cases(), 'value'))->description('With group_by time: how much time each bucket covers. Defaults to one that suits the period; describe-traffic-fields lists which each period allows.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('With a field group_by: how many rows to return, busiest first. Default 20, at most 100.'),
            'chart' => $schema->boolean()->description('Also return a PNG chart: bars for a field group_by, columns for time. Default false.'),
        ];
    }

    /**
     * The interval a time breakdown is counted in: the one asked for, if the period allows it, or the
     * period's default. A sentence saying what's allowed when it doesn't.
     */
    private function interval(?string $choice, TrafficPeriod $period): ChartInterval|string
    {
        $allowed = ChartInterval::for($period);

        if ($choice === null) {
            return ChartInterval::default($period);
        }

        $interval = ChartInterval::from($choice);

        return in_array($interval, $allowed, true)
            ? $interval
            : "A {$period->value} period can be counted by ".implode(', ', array_map(fn (ChartInterval $allowed) => $allowed->value, $allowed)).", not {$choice}.";
    }

    /**
     * Draw the counts: columns over time, bars for a field breakdown, or a bar each for the totals.
     *
     * @param  array{requests: int, unique_ips: int}  $totals
     * @param  list<array{start: string, label: string, requests: int, passed: int, suspicious: int}>|null  $buckets
     * @param  list<array{value: int|string|null, label: string, requests: int, unique_ips: int, search: string}>|null  $rows
     */
    private function draw(TrafficChart $chart, string $readsAs, TrafficPeriod $period, array $totals, ?array $buckets, ?array $rows, ?ChartInterval $interval, ?string $groupBy): string
    {
        $title = Str::of($readsAs)->rtrim('.')->toString();
        $subtitle = "{$period->label()} · ".number_format($totals['requests']).' requests from '.number_format($totals['unique_ips']).' IPs';

        if ($buckets !== null && $interval !== null) {
            return $chart->columns($title, "{$subtitle} · per {$interval->value}", array_map(fn (array $bucket) => ['label' => $bucket['label'], 'value' => $bucket['requests']], $buckets));
        }

        if ($rows !== null) {
            return $chart->bars($title, "{$subtitle} · by {$groupBy}", array_map(fn (array $row) => ['label' => $row['label'], 'value' => $row['requests']], $rows));
        }

        return $chart->bars($title, $subtitle, [['label' => 'Requests', 'value' => $totals['requests']], ['label' => 'Unique IPs', 'value' => $totals['unique_ips']]]);
    }
}
