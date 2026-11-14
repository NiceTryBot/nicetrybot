<?php

namespace NiceTry\SwarmMode;

use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Gym\Contracts\GymTriggerSource;
use App\Models\Domain;
use App\Models\ScopeVerdict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use NiceTry\SwarmMode\Models\ManualSwarm;
use NiceTry\SwarmMode\Models\SwarmRule;

/**
 * Has the edge ask the gym check about every request to a site or URL while it's in Swarm Mode, on the
 * domains in Enforce mode. Whether a request falls under Swarm Mode is known only to NiceTryBot, so
 * without this the edge would ask only about requests it was already asking about (a gym cookie, a
 * routine's clients), and the Bouncer would never see a fresh visitor there. When Swarm Mode ends, the
 * trigger goes with it on the edge's next poll.
 */
class SwarmTriggerSource implements GymTriggerSource
{
    public function cookieTriggers(): array
    {
        $scopes = ScopeVerdict::query()->active()->whereIn('edge_rule_id', SwarmRule::query()->select('edge_rule_id'))->get(['team_id', 'domain_id', 'path_id'])
            ->concat(ManualSwarm::query()->active()->get(['team_id', 'domain_id', 'path_id']));

        if ($scopes->isEmpty()) {
            return [];
        }

        $paths = DB::table('request_paths')->whereIn('id', $scopes->pluck('path_id')->filter()->unique()->values())->pluck('path', 'id');
        $domains = Domain::query()
            ->whereIn('team_id', $scopes->pluck('team_id')->unique()->values())
            ->where('protection_mode', ProtectionMode::Enforce)
            ->where('status', DomainStatus::Active)
            ->withEdgeHostnames()
            ->get(['id', 'team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www']);
        $triggers = [];

        foreach ($scopes as $scope) {
            $path = $scope->path_id === null ? '*' : ($paths[$scope->path_id] ?? null);

            // A path the glob would read as a pattern is left to its site's swarm, if any.
            if ($path === null || ($path !== '*' && strpbrk($path, '*?[') !== false)) {
                continue;
            }

            $hosts = $this->hosts($domains, $scope->team_id, $scope->domain_id);

            if ($hosts !== []) {
                $triggers[$path.'|'.implode(',', $hosts)] = ['hosts' => $hosts, 'path' => $path, 'limited' => false];
            }
        }

        return array_values($triggers);
    }

    /**
     * The hostnames a scope covers: its site's, or every one of the team's sites when it's a path on all.
     *
     * @param  Collection<int, Domain>  $domains
     * @return list<string>
     */
    private function hosts(Collection $domains, int $teamId, ?int $domainId): array
    {
        return $domains
            ->filter(fn (Domain $domain) => $domain->team_id === $teamId && ($domainId === null || $domain->id === $domainId))
            ->flatMap(fn (Domain $domain) => $domain->edgeHostnames())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
