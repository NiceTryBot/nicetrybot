<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Enums\ProtectionMode;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Shared origins, from every source tagged in under TAG whose add-on is on (App\Support\SharedOriginSource):
 * backends serving many sites, each a domain whose origin_id points at one. The core reads them here
 * wherever a site differs from a domain of its own: which hostnames the edge serves it under, which
 * unknown hostnames become new sites, where its settings come from and whether its protection mode
 * follows its origin's.
 *
 * With no source (none installed, or every one's add-on off), there are no shared origins: every domain
 * is one of its own, served under its hostname, its preview, and www. when it turned that on, with the
 * settings it holds. Its origin_id stays as it is, so the sites are whole again once a source is back.
 *
 * Scoped, so under Octane each request starts over; the sources are resolved once a request, since the
 * edge's lists ask about every domain.
 */
#[Scoped]
class SharedOrigins
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'domains.shared-origins';

    /**
     * Every tagged source, on or off, once resolved.
     *
     * @var list<SharedOriginSource>|null
     */
    private ?array $tagged = null;

    public function __construct(private Container $container, private AddonSwitchboard $switchboard)
    {
        //
    }

    /**
     * The sources whose add-on is on, in the order they were tagged.
     *
     * @return list<SharedOriginSource>
     */
    public function sources(): array
    {
        $this->tagged ??= array_values(array_filter(
            iterator_to_array($this->container->tagged(self::TAG), false),
            fn (mixed $source): bool => $source instanceof SharedOriginSource,
        ));

        return array_values(array_filter(
            $this->switchboard->active($this->tagged),
            fn (mixed $source): bool => $source instanceof SharedOriginSource,
        ));
    }

    /**
     * Determine if anything keeps shared origins right now. Without, every domain is one of its own.
     */
    public function areKept(): bool
    {
        return $this->sources() !== [];
    }

    /**
     * The team's shared origins, by name.
     *
     * @return Collection<int, SharedOrigin>
     */
    public function origins(Team $team): Collection
    {
        return collect($this->sources())
            ->flatMap(fn (SharedOriginSource $source): array => $source->origins($team))
            ->sortBy([['name', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * The shared origin a domain is a site of, or null for a domain of its own.
     */
    public function originOf(Domain $domain): ?SharedOrigin
    {
        if ($domain->origin_id === null) {
            return null;
        }

        foreach ($this->sources() as $source) {
            if ($origin = $source->originOf($domain)) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * Load what deciding the domains' edge hostnames reads, for the sites among them, at once.
     *
     * @param  EloquentCollection<int, Domain>  $domains
     */
    public function load(EloquentCollection $domains): void
    {
        $sites = $domains->whereNotNull('origin_id');

        if ($sites->isEmpty()) {
            return;
        }

        foreach ($this->sources() as $source) {
            $source->load($sites);
        }
    }

    /**
     * Determine if the edge serves www.<hostname> as a site of a shared origin, or null for a domain of
     * its own (which decides for itself).
     */
    public function servesWww(Domain $domain): ?bool
    {
        if ($domain->origin_id === null) {
            return null;
        }

        foreach ($this->sources() as $source) {
            $serves = $source->servesWww($domain);

            if ($serves !== null) {
                return $serves;
            }
        }

        return null;
    }

    /**
     * Add to a query of domains, each as an alternative, the sites that every source serves under www. too.
     *
     * @param  Builder<Domain>  $query
     */
    public function orWhereServesWww(Builder $query): void
    {
        foreach ($this->sources() as $source) {
            $query->orWhere(fn (Builder $query) => $source->whereServesWww($query));
        }
    }

    /**
     * Determine if the edge turns a hostname away though it would be served as the domain. A domain of
     * its own never does.
     */
    public function refuses(Domain $domain, string $hostname): bool
    {
        if ($domain->origin_id === null) {
            return false;
        }

        foreach ($this->sources() as $source) {
            if ($source->refuses($domain, $hostname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site a shared origin adds for a hostname no domain has, when one vouches for it.
     */
    public function claim(string $hostname): ?Domain
    {
        foreach ($this->sources() as $source) {
            if ($domain = $source->claim($hostname)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Determine if a site of a shared origin sets its own protection mode instead of its origin's.
     */
    public function keepsOwnProtectionMode(Domain $domain): bool
    {
        if ($domain->origin_id === null) {
            return false;
        }

        foreach ($this->sources() as $source) {
            if ($source->keepsOwnProtectionMode($domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set a site's protection mode through its shared origin: its own, or null to follow the origin's
     * again. False, with nothing changed, for a domain of its own.
     */
    public function setProtectionMode(Domain $domain, ?ProtectionMode $mode): bool
    {
        if ($domain->origin_id === null) {
            return false;
        }

        foreach ($this->sources() as $source) {
            if ($source->setProtectionMode($domain, $mode)) {
                return true;
            }
        }

        return false;
    }
}
