<?php

namespace NiceTry\Lockdown\Mcp;

use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use App\Support\SharedOrigin;
use App\Support\SharedOrigins;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Lockdown\Models\LockdownAllowedIp;

/**
 * A lockdown tool: works on the token's team's lockdown, as the token's user, the way the Lockdown page does.
 */
abstract class LockdownTool extends TeamTool
{
    /**
     * How many locked hostnames a summary lists before it only counts them.
     */
    protected const int MAX_LISTED_SITES = 100;

    /**
     * Get the team's lockdown once the token's user may do the given thing to it ('view' or 'update').
     */
    protected function lockdown(string $ability): Lockdown
    {
        $lockdown = Lockdown::for($this->team());

        Gate::forUser($this->user())->authorize($ability, $lockdown);

        return $lockdown;
    }

    /**
     * Find the team's sites by hostname. Hostnames are matched as typed, less case, a scheme, a path and a
     * trailing dot, so "https://Shop.Example/" finds shop.example.
     *
     * @param  array<mixed>  $hostnames  As the agent sent them: anything that isn't a string is left out.
     * @return array{0: Collection<int, Domain>, 1: list<string>} The sites found, and the hostnames the team has no site for.
     */
    protected function findSites(array $hostnames): array
    {
        $wanted = collect($hostnames)
            ->filter(fn (mixed $hostname) => is_string($hostname))
            ->map(fn (string $hostname) => Str::of($hostname)->trim()->lower()->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')->before('/')->rtrim('.')->toString())
            ->filter()
            ->unique()
            ->values();

        $sites = $this->team()->domains()->whereIn('hostname', $wanted)->orderBy('hostname')->get();

        return [$sites, array_values($wanted->diff($sites->pluck('hostname'))->all())];
    }

    /**
     * The hostnames of some sites, in order.
     *
     * @param  Collection<int, Domain>  $sites
     * @return list<string>
     */
    private function hostnames(Collection $sites): array
    {
        return array_values($sites->map(fn (Domain $site) => $site->hostname)->all());
    }

    /**
     * Describe the lockdown: whether it's engaged, what it covers, who gets through, and where to see it.
     *
     * @return array{
     *     engaged: bool,
     *     engaged_since: string|null,
     *     site_count: int,
     *     locked_count: int,
     *     locked_sites: list<string>,
     *     locked_sites_listed: int,
     *     pooled_origins: list<array{name: string, sites: int}>,
     *     released_sites: list<string>,
     *     allowed_ips: list<array{range: string, label: string|null}>,
     *     edges: list<array{checked_in_at: string, has_latest_change: bool}>,
     *     page_url: string,
     * }
     */
    protected function summary(Lockdown $lockdown): array
    {
        $lockdown->refresh();
        $team = $this->team();

        $lockedSites = $this->hostnames($lockdown->lockedDomains()->orderBy('hostname')->limit(self::MAX_LISTED_SITES)->get());
        $pooled = $lockdown->pooledOrigins()->pluck('origin_id');

        return [
            'engaged' => $lockdown->isEngaged(),
            'engaged_since' => $lockdown->engaged_at?->toIso8601String(),
            'site_count' => $team->domains()->count(),
            'locked_count' => $lockdown->lockedDomains()->count(),
            'locked_sites' => $lockedSites,
            'locked_sites_listed' => count($lockedSites),
            'pooled_origins' => array_values(app(SharedOrigins::class)->origins($team)
                ->filter(fn (SharedOrigin $origin) => $pooled->contains($origin->id))
                ->map(fn (SharedOrigin $origin) => ['name' => $origin->name, 'sites' => $origin->siteCount])
                ->all()),
            'released_sites' => $this->hostnames($lockdown->domainDecisions()->wherePivot('locked', false)->orderBy('hostname')->get()),
            'allowed_ips' => array_values($lockdown->allowedIps()->orderBy('range')->get()
                ->map(fn (LockdownAllowedIp $allowedIp) => ['range' => $allowedIp->range, 'label' => $allowedIp->label])
                ->all()),
            'edges' => array_values(Lockdown::edgeCheckIns()
                ->filter(fn (Carbon $at) => $at->isAfter(now()->subMinutes(5)))
                ->map(fn (Carbon $at) => ['checked_in_at' => $at->toIso8601String(), 'has_latest_change' => $at->greaterThanOrEqualTo($lockdown->updated_at)])
                ->all()),
            'page_url' => route('lockdown.index', $team),
        ];
    }
}
