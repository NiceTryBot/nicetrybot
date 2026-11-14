<?php

namespace NiceTry\Honeypots\Edge;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Support\HoneypotFeedContributor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use NiceTry\Honeypots\Models\HoneypotTrap;

/**
 * The enabled honeypot traps, in the edge's honeypot feed, oldest first: for each, the hostnames of the
 * active domains it covers (every one of the team's, or its one domain), its honeypot's origin, secret
 * and timeout, and its globs and regular expressions. Kept a few seconds, as edges poll often.
 */
class HoneypotList implements HoneypotFeedContributor
{
    /**
     * How long the list is kept between polls, in seconds. Changes to a trap or honeypot drop it at once.
     */
    private const int CACHE_SECONDS = 10;

    public function honeypots(): array
    {
        return Cache::remember(HoneypotTrap::FEED_CACHE_KEY, self::CACHE_SECONDS, fn () => $this->build());
    }

    /**
     * @return list<array{id: int, honeypot: int, hosts: list<string>, origin: string, secret: string, timeout: int, globs: list<string>, regexes: list<string>}>
     */
    private function build(): array
    {
        $traps = HoneypotTrap::query()->where('enabled', true)->with('honeypot')->orderBy('id')->get();

        if ($traps->isEmpty()) {
            return [];
        }

        $domains = Domain::query()
            ->whereIn('team_id', $traps->pluck('team_id')->unique())
            ->where('status', DomainStatus::Active)
            ->orderBy('hostname')
            ->withEdgeHostnames()
            ->get(['id', 'team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www'])
            ->groupBy('team_id');

        return $traps
            ->map(fn (HoneypotTrap $trap) => $this->payload($trap, $domains->get($trap->team_id, collect())))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One trap as the edge takes it, or null when it covers no active domain or its honeypot isn't the
     * team's own.
     *
     * @param  Collection<int, Domain>  $teamDomains
     * @return array{id: int, honeypot: int, hosts: list<string>, origin: string, secret: string, timeout: int, globs: list<string>, regexes: list<string>}|null
     */
    private function payload(HoneypotTrap $trap, Collection $teamDomains): ?array
    {
        if ($trap->honeypot->team_id !== $trap->team_id) {
            return null;
        }

        $covered = $trap->isTeamWide() ? $teamDomains : $teamDomains->where('id', $trap->domain_id);
        $hosts = $covered->flatMap(fn (Domain $domain) => $domain->edgeHostnames())->unique()->sort()->values()->all();

        if ($hosts === []) {
            return null;
        }

        return [
            'id' => $trap->id,
            'honeypot' => $trap->honeypot->id,
            'hosts' => $hosts,
            'origin' => $trap->honeypot->originBase(),
            'secret' => $trap->honeypot->secret,
            'timeout' => $trap->honeypot->timeout_seconds,
            'globs' => $trap->globs(),
            'regexes' => $trap->regexes(),
        ];
    }
}
