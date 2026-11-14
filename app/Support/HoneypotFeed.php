<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The list the edge's honeypot module polls, put together from every contributor tagged in (an add-on's
 * honeypot traps). It's always a list, empty when nothing is trapped or nothing fills it: the edge takes
 * an answer without one as a failure and keeps the last list it had, so an install without honeypots
 * must still say that nothing is trapped.
 */
class HoneypotFeed
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.honeypots';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every contributor's traps.
     *
     * @return list<array<string, mixed>>
     */
    public function honeypots(): array
    {
        $honeypots = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $contributor) {
            if ($contributor instanceof HoneypotFeedContributor) {
                $honeypots = [...$honeypots, ...array_values($contributor->honeypots())];
            }
        }

        return $honeypots;
    }
}
