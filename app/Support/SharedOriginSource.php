<?php

namespace App\Support;

use App\Enums\ProtectionMode;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Something that keeps shared origins, like the origins add-on: one backend serving many sites, each a
 * domain whose origin_id points at it and whose settings are copied from it. It says how the edge serves
 * those sites (under www. too, and which names it turns away), adds a site on the spot when one of its
 * origins vouches for a hostname the edge hasn't heard of, and keeps a site's protection mode following
 * its origin's unless the site sets its own. Sources are tagged in the container under
 * SharedOrigins::TAG.
 *
 * A source is only asked about sites (domains whose origin_id is set), and answers null or false for
 * a site whose origin it doesn't keep.
 */
interface SharedOriginSource
{
    /**
     * The team's shared origins.
     *
     * @return list<SharedOrigin>
     */
    public function origins(Team $team): array;

    /**
     * The shared origin a site belongs to, or null when the source doesn't keep it.
     */
    public function originOf(Domain $site): ?SharedOrigin;

    /**
     * Load what servesWww() and refuses() read for many sites at once, so a list of thousands costs a
     * query or two rather than one a site.
     *
     * @param  Collection<int, Domain>  $sites
     */
    public function load(Collection $sites): void;

    /**
     * Determine if the edge serves www.<hostname> as the site, or null when the source doesn't keep it.
     * Never asked about a hostname that already starts with www.
     */
    public function servesWww(Domain $site): ?bool;

    /**
     * Limit a query of domains to the source's sites that the edge serves under www. too: servesWww() in
     * SQL, short of the names refuses() turns away, which are checked on their own.
     *
     * @param  Builder<Domain>  $query
     */
    public function whereServesWww(Builder $query): void;

    /**
     * Determine if the edge turns a hostname away though it would be served as the site (its own name,
     * its www. name or its preview), like a www. name under a group of sites that doesn't serve www.
     */
    public function refuses(Domain $site, string $hostname): bool;

    /**
     * The site to serve a hostname no domain has, added on the spot when one of the source's origins
     * vouches for it, or null when none does. The hostname comes valid and in lower case.
     */
    public function claim(string $hostname): ?Domain;

    /**
     * Determine if the site sets its own protection mode instead of following its origin's.
     */
    public function keepsOwnProtectionMode(Domain $site): bool;

    /**
     * Set the site's protection mode: its own, which it keeps when its origin's changes, or null to follow
     * its origin's again. False, with nothing changed, when the source doesn't keep the site.
     */
    public function setProtectionMode(Domain $site, ?ProtectionMode $mode): bool;
}
