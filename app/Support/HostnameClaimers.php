<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\Domain;
use Illuminate\Contracts\Container\Container;

/**
 * Every claimer tagged in under TAG whose add-on is on (App\Support\HostnameClaimer), asked in turn for
 * a hostname no domain and no shared origin claims. With none, an unknown hostname stays unknown.
 */
class HostnameClaimers
{
    /**
     * The container tag claimers register under.
     */
    public const string TAG = 'domains.hostname-claimers';

    public function __construct(private Container $container, private AddonSwitchboard $switchboard)
    {
        //
    }

    /**
     * The domain the first claimer adds for the hostname, or null when none does.
     */
    public function claim(string $hostname): ?Domain
    {
        foreach ($this->switchboard->active($this->container->tagged(self::TAG)) as $claimer) {
            if ($claimer instanceof HostnameClaimer && $domain = $claimer->claim($hostname)) {
                return $domain;
            }
        }

        return null;
    }
}
