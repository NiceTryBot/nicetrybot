<?php

namespace App\Actions\Analytics;

use App\Data\BreakdownRow;
use App\Data\BreakdownSummary;
use App\Data\Ja4Fingerprint;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\TrafficBreakdown;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Locale;

class SummarizeBreakdown
{
    /**
     * Group a team's edge requests by network, country, user agent or TLS fingerprint, busiest first.
     */
    public function handle(
        Team $team,
        TrafficBreakdown $breakdown,
        TrafficPeriod $period,
        ?Domain $domain = null,
        int $limit = 50,
        ?CarbonImmutable $now = null,
    ): BreakdownSummary {
        $query = fn (): Builder => EdgeRequest::query()->toBase()
            ->whereIn('edge_requests.domain_id', $team->domains()->select('id'))
            ->when($domain, fn (Builder $query) => $query->where('edge_requests.domain_id', $domain->id))
            ->where('edge_requests.occurred_at', '>=', $period->start($now));

        $measures = 'count(*) as requests, sum(case when edge_requests.action <> ? then 1 else 0 end) as suspicious, count(distinct edge_requests.client_ip) as unique_ips';
        $bindings = [RequestAction::Passed->value];

        $grouped = match ($breakdown) {
            TrafficBreakdown::Networks => $query()
                ->leftJoin('networks', 'networks.asn', '=', 'edge_requests.asn')
                ->selectRaw("edge_requests.asn as row_key, networks.name as label, networks.kind as kind, count(distinct edge_requests.ja4_id) as related, {$measures}", $bindings)
                ->groupBy('edge_requests.asn', 'networks.name', 'networks.kind'),
            TrafficBreakdown::Countries => $query()
                ->leftJoin('locations', 'locations.id', '=', 'edge_requests.location_id')
                ->selectRaw("locations.country_code as row_key, count(distinct locations.city) as related, {$measures}", $bindings)
                ->groupBy('locations.country_code'),
            TrafficBreakdown::UserAgents => $query()
                ->leftJoin('user_agents', 'user_agents.id', '=', 'edge_requests.user_agent_id')
                ->selectRaw("edge_requests.user_agent_id as row_key, user_agents.value as label, count(distinct edge_requests.ja4_id) as related, {$measures}", $bindings)
                ->groupBy('edge_requests.user_agent_id', 'user_agents.value'),
            TrafficBreakdown::Fingerprints => $query()
                ->leftJoin('tls_fingerprints', 'tls_fingerprints.id', '=', 'edge_requests.ja4_id')
                ->selectRaw("edge_requests.ja4_id as row_key, tls_fingerprints.value as label, count(distinct edge_requests.user_agent_id) as related, {$measures}", $bindings)
                ->groupBy('edge_requests.ja4_id', 'tls_fingerprints.value'),
            TrafficBreakdown::Categories => $query()
                ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
                ->selectRaw("request_paths.category as row_key, count(distinct edge_requests.path_id) as related, {$measures}", $bindings)
                ->groupBy('request_paths.category'),
        };

        $rows = $grouped->orderByDesc('requests')->limit($limit)->get()
            ->map(fn (object $row) => $this->toRow($breakdown, $row))
            ->all();

        return new BreakdownSummary(
            breakdown: $breakdown,
            period: $period,
            total: $query()->count(),
            rows: $rows,
        );
    }

    /**
     * Label a grouped row for display.
     */
    private function toRow(TrafficBreakdown $breakdown, object $row): BreakdownRow
    {
        $key = $row->row_key;

        [$label, $detail] = match ($breakdown) {
            TrafficBreakdown::Networks => $key === null
                ? [__('Unknown network'), null]
                : [$row->label ?? 'AS'.$key, 'AS'.$key],
            TrafficBreakdown::Countries => $key === null
                ? [__('Unknown location'), null]
                : [Locale::getDisplayRegion('-'.$key, app()->getLocale()) ?: $key, $key],
            TrafficBreakdown::UserAgents => $key === null
                ? [__('No user agent sent'), null]
                : [$row->label, null],
            TrafficBreakdown::Fingerprints => $key === null
                ? [__('No fingerprint (plain HTTP)'), null]
                : [$row->label, Ja4Fingerprint::tryFrom($row->label)?->summary()],
            TrafficBreakdown::Categories => $key === null
                ? [__('Uncategorized'), __('Pages and paths that match no pattern yet.')]
                : [PathCategory::from((int) $key)->slug(), PathCategory::from((int) $key)->description()],
        };

        $category = $breakdown === TrafficBreakdown::Categories && $key !== null ? PathCategory::from((int) $key) : null;

        return new BreakdownRow(
            key: match (true) {
                $key === null => null,
                $category !== null => $category->slug(),
                is_numeric($key) => (int) $key,
                default => (string) $key,
            },
            label: (string) $label,
            detail: $detail,
            requests: (int) $row->requests,
            suspicious: (int) $row->suspicious,
            uniqueIps: (int) $row->unique_ips,
            related: (int) $row->related,
            isHosting: isset($row->kind) && (int) $row->kind === NetworkKind::Hosting->value,
            patterns: $category?->patterns() ?? [],
            rule: $category?->caddyMatcher(),
        );
    }
}
