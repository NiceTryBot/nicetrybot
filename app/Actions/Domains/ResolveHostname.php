<?php

namespace App\Actions\Domains;

use App\Models\Domain;
use App\Rules\Hostname;
use App\Support\SharedOrigins;
use Illuminate\Support\Str;

class ResolveHostname
{
    public function __construct(private SharedOrigins $sharedOrigins)
    {
        //
    }

    /**
     * Find the domain the edge should serve for a hostname: a registered domain that's live (by its own
     * hostname or its preview), or a site a shared origin adds on the spot because it vouches for the
     * hostname (App\Support\SharedOrigins). Null when nobody claims it.
     * www.site is served as site when site serves www. (its shared origin folds www, or it turned www.
     * on). A name the domain it would be served as turns away, like a www. name under a group of an
     * origin's sites that doesn't serve www., is refused without asking anyone, so it never gets a
     * certificate.
     */
    public function handle(string $hostname): ?Domain
    {
        $hostname = Str::lower(rtrim(trim($hostname), '.'));

        if (! Hostname::isValid($hostname)) {
            return null;
        }

        $domain = Domain::query()->withEdgeHostnames()->servedAs($hostname)->first();

        if ($domain) {
            return $domain->isServable() && ! $domain->refusesHostname($hostname) ? $domain : null;
        }

        $bare = Domain::withoutWww($hostname);
        $bareDomain = $bare === null ? null : Domain::query()->withEdgeHostnames()->firstWhere('hostname', $bare);

        if ($bareDomain?->refusesHostname($hostname)) {
            return null;
        }

        if ($bareDomain?->servesWww()) {
            return $bareDomain->isServable() ? $bareDomain : null;
        }

        return $this->sharedOrigins->claim($hostname);
    }
}
