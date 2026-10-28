<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The list the edge's tarpit module polls, put together from every contributor tagged in (an add-on's
 * tarpits). It's always a list, empty when no tarpit is set up or nothing fills it: the edge takes an
 * answer without one as a failure and keeps the last list it had.
 */
class TarpitFeed
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.tarpits';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every contributor's tarpits, for the edge at the given address.
     *
     * @return list<array<string, mixed>>
     */
    public function tarpits(string $edge): array
    {
        $tarpits = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $contributor) {
            if ($contributor instanceof TarpitFeedContributor) {
                $tarpits = [...$tarpits, ...array_values($contributor->tarpits($edge))];
            }
        }

        return $tarpits;
    }
}
