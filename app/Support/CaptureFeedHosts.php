<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Collection;

/**
 * The hostnames the edge serves for each team, for the capture feed's entries. One is made for each
 * poll and handed to every contributor, so teams several of them name are looked up once.
 */
final class CaptureFeedHosts
{
    /**
     * @var Collection<int, Domain>
     */
    private Collection $domains;

    /**
     * @var array<int, true>
     */
    private array $loadedTeamIds = [];

    public function __construct()
    {
        $this->domains = new Collection;
    }

    /**
     * Look up the active and paused domains of the teams given, in one query, skipping teams already
     * looked up.
     *
     * @param  iterable<int>  $teamIds
     */
    public function load(iterable $teamIds): void
    {
        $missing = [];

        foreach ($teamIds as $teamId) {
            if (! isset($this->loadedTeamIds[$teamId])) {
                $this->loadedTeamIds[$teamId] = true;
                $missing[] = $teamId;
            }
        }

        if ($missing === []) {
            return;
        }

        $this->domains = $this->domains->merge(Domain::query()
            ->whereIn('team_id', $missing)
            ->whereIn('status', [DomainStatus::Active, DomainStatus::Paused])
            ->withEdgeHostnames()
            ->get(['id', 'team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www']));
    }

    /**
     * Get the hostnames an entry applies to: its one domain, or every one of its team's, with www.
     * where the domain serves it. The team is looked up first if it hasn't been.
     *
     * @return list<string>
     */
    public function for(int $teamId, ?int $domainId = null): array
    {
        $this->load([$teamId]);

        return array_values($this->domains
            ->where('team_id', $teamId)
            ->when($domainId !== null, fn (Collection $domains) => $domains->where('id', $domainId))
            ->flatMap(fn (Domain $domain) => $domain->edgeHostnames())
            ->unique()
            ->sort()
            ->all());
    }
}
