<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The list the edge's lockdown module polls, put together from every contributor tagged in (an
 * add-on's engaged lockdowns). It's always a list, empty when nothing is locked or nothing fills it:
 * the edge takes an answer without one (or no answer) as a failure and keeps the last list it had, so
 * an install without lockdowns must still say that nothing is locked.
 */
class LockdownFeed
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.lockdowns';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every contributor's entries, for the edge at the given address.
     *
     * @return list<array<string, mixed>>
     */
    public function lockdowns(string $edge): array
    {
        $lockdowns = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $contributor) {
            if ($contributor instanceof LockdownFeedContributor) {
                $lockdowns = [...$lockdowns, ...array_values($contributor->lockdowns($edge))];
            }
        }

        return $lockdowns;
    }
}
