<?php

namespace NiceTry\Rewrites\Edge;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Support\RewriteFeedContributor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use NiceTry\Rewrites\Models\ResponseRewrite;

/**
 * The enabled response rewrites, in the edge's rewrite feed, oldest first: for each, the hostnames of
 * the active domains it covers (every one of the team's, or its one domain, in either protection mode:
 * a rewrite isn't protection), what to find and what it becomes. Kept a few seconds, as edges poll often.
 */
class RewriteList implements RewriteFeedContributor
{
    /**
     * How long the list is kept between polls, in seconds. Changes to a rewrite drop it at once.
     */
    private const int CACHE_SECONDS = 10;

    public function rewrites(): array
    {
        return Cache::remember(ResponseRewrite::FEED_CACHE_KEY, self::CACHE_SECONDS, fn () => $this->build());
    }

    /**
     * @return list<array{id: int, hosts: list<string>, find: string, replace: string, ignore_case: bool, in: string, headers: bool}>
     */
    private function build(): array
    {
        $rewrites = ResponseRewrite::query()->where('enabled', true)->orderBy('id')->get();

        if ($rewrites->isEmpty()) {
            return [];
        }

        $domains = Domain::query()
            ->whereIn('team_id', $rewrites->pluck('team_id')->unique())
            ->where('status', DomainStatus::Active)
            ->orderBy('hostname')
            ->withEdgeHostnames()
            ->get(['id', 'team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www'])
            ->groupBy('team_id');

        return $rewrites
            ->map(fn (ResponseRewrite $rewrite) => $this->payload($rewrite, $domains->get($rewrite->team_id, collect())))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One rewrite as the edge takes it, or null when it covers no active domain.
     *
     * @param  Collection<int, Domain>  $teamDomains
     * @return array{id: int, hosts: list<string>, find: string, replace: string, ignore_case: bool, in: string, headers: bool}|null
     */
    private function payload(ResponseRewrite $rewrite, Collection $teamDomains): ?array
    {
        $covered = $rewrite->isTeamWide() ? $teamDomains : $teamDomains->where('id', $rewrite->domain_id);
        $hosts = $covered->flatMap(fn (Domain $domain) => $domain->edgeHostnames())->unique()->sort()->values()->all();

        if ($hosts === []) {
            return null;
        }

        return [
            'id' => $rewrite->id,
            'hosts' => $hosts,
            'find' => $rewrite->find,
            'replace' => $rewrite->replace,
            'ignore_case' => $rewrite->ignore_case,
            'in' => $rewrite->content->slug(),
            'headers' => $rewrite->in_headers,
        ];
    }
}
