<?php

namespace App\Actions\Analytics;

use App\Data\NotFoundSummary;
use App\Enums\NotFoundCategory;
use App\Enums\NotFoundGrouping;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class SummarizeNotFound
{
    /**
     * Summarize the 404s a team's domains returned, optionally for a single domain. Categories come from
     * the category stored on each path (set by its patterns when first seen, or by hand), so the
     * database does the sorting and only the top paths and domains come back.
     */
    public function handle(Team $team, TrafficPeriod $period, ?Domain $domain = null, int $pathLimit = 25, int $domainLimit = 12, ?CarbonImmutable $now = null): NotFoundSummary
    {
        $query = $this->query($team, $period, $domain, $now);

        $categories = $query()
            ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->toBase()
            ->selectRaw('request_paths.category as category, count(*) as hits, count(distinct edge_requests.path_id) as paths')
            ->groupBy('request_paths.category')
            ->get();

        $domains = $query()
            ->join('domains', 'domains.id', '=', 'edge_requests.domain_id')
            ->toBase()
            ->selectRaw('domains.hostname as hostname, count(*) as hits')
            ->groupBy('domains.hostname')
            ->orderByDesc('hits')
            ->orderBy('domains.hostname')
            ->limit($domainLimit)
            ->get()
            ->map(fn (object $row) => ['hostname' => $row->hostname, 'hits' => (int) $row->hits]);

        return new NotFoundSummary(
            total: (int) $categories->sum('hits'),
            uniquePaths: (int) $categories->sum('paths'),
            uniqueIps: (int) $query()->distinct()->count('client_ip'),
            categoryTotals: $categories->groupBy(fn (object $row) => NotFoundCategory::fromPathCategory($row->category)->value)
                ->map(fn ($rows) => (int) $rows->sum('hits'))
                ->all(),
            domains: $domains->all(),
            paths: $this->topPaths($team, $period, $domain, $pathLimit, $now),
        );
    }

    /**
     * The most requested missing paths, busiest first.
     *
     * @return array<int, array{path: string, category: NotFoundCategory, hits: int, uniqueIps: int, domains: int, lastSeenAt: string}>
     */
    public function topPaths(Team $team, TrafficPeriod $period, ?Domain $domain = null, int $limit = 25, ?CarbonImmutable $now = null): array
    {
        return ($this->query($team, $period, $domain, $now))()
            ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->toBase()
            ->selectRaw('request_paths.path as path, request_paths.category as category, count(*) as hits, count(distinct client_ip) as unique_ips, count(distinct domain_id) as domains, max(occurred_at) as last_seen_at')
            ->groupBy('request_paths.id', 'request_paths.path', 'request_paths.category')
            ->orderByDesc('hits')
            ->orderBy('request_paths.path')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row) => $this->pathRow($row))
            ->all();
    }

    /**
     * The busiest groups of missing paths, busiest first, each with its own most requested paths. A
     * group's counts cover all of its 404s, not just the paths listed.
     *
     * @return array<int, array{key: string|null, hits: int, uniqueIps: int, distinctPaths: int, lastSeenAt: string, paths: array<int, array{path: string, category: NotFoundCategory, hits: int, uniqueIps: int, domains: int, lastSeenAt: string}>}>
     */
    public function groupedPaths(Team $team, TrafficPeriod $period, NotFoundGrouping $grouping, ?Domain $domain = null, int $groupLimit = 15, ?CarbonImmutable $now = null): array
    {
        $column = $grouping->column();

        $query = fn () => ($this->query($team, $period, $domain, $now))()
            ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->when($grouping === NotFoundGrouping::Tag, fn (Builder $query) => $query->leftJoin('bot_tags', 'bot_tags.id', '=', 'edge_requests.bot_tag_id'))
            ->toBase();

        $rankedPaths = $query()
            ->selectRaw("{$column} as group_key, request_paths.path as path, request_paths.category as category, count(*) as hits, count(distinct client_ip) as unique_ips, count(distinct domain_id) as domains, max(occurred_at) as last_seen_at, row_number() over (partition by {$column} order by count(*) desc, request_paths.path) as path_rank")
            ->groupByRaw($column)
            ->groupBy('request_paths.id', 'request_paths.path', 'request_paths.category');

        $paths = DB::query()
            ->fromSub($rankedPaths, 'ranked_paths')
            ->where('path_rank', '<=', $grouping->pathsPerGroup())
            ->orderBy('path_rank')
            ->get()
            ->groupBy(fn (object $row) => (string) $row->group_key);

        return $query()
            ->selectRaw("{$column} as group_key, count(*) as hits, count(distinct client_ip) as unique_ips, count(distinct edge_requests.path_id) as distinct_paths, max(occurred_at) as last_seen_at")
            ->groupByRaw($column)
            ->orderByDesc('hits')
            ->orderBy('group_key')
            ->limit($groupLimit)
            ->get()
            ->map(fn (object $row) => [
                'key' => $row->group_key === null ? null : (string) $row->group_key,
                'hits' => (int) $row->hits,
                'uniqueIps' => (int) $row->unique_ips,
                'distinctPaths' => (int) $row->distinct_paths,
                'lastSeenAt' => $row->last_seen_at,
                'paths' => $paths->get((string) $row->group_key, collect())->map(fn (stdClass $path) => $this->pathRow($path))->all(),
            ])
            ->all();
    }

    /**
     * @return array{path: string, category: NotFoundCategory, hits: int, uniqueIps: int, domains: int, lastSeenAt: string}
     */
    private function pathRow(stdClass $row): array
    {
        return [
            'path' => $row->path,
            'category' => NotFoundCategory::fromPathCategory($row->category),
            'hits' => (int) $row->hits,
            'uniqueIps' => (int) $row->unique_ips,
            'domains' => (int) $row->domains,
            'lastSeenAt' => $row->last_seen_at,
        ];
    }

    /**
     * @return \Closure(): Builder<EdgeRequest>
     */
    private function query(Team $team, TrafficPeriod $period, ?Domain $domain, ?CarbonImmutable $now): \Closure
    {
        return fn (): Builder => EdgeRequest::query()
            ->whereIn('domain_id', $team->domains()->select('id'))
            ->when($domain, fn (Builder $query) => $query->where('domain_id', $domain->id))
            ->where('status_code', 404)
            ->where('occurred_at', '>=', $period->start($now));
    }
}
