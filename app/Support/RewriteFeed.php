<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The list the edge's rewrite module polls, put together from every contributor tagged in (an add-on's
 * response rewrites). It's always a list, empty when nothing is rewritten or nothing fills it: the edge
 * takes an answer without one as a failure and keeps the last list it had, so an install without
 * rewrites must still say that nothing is rewritten.
 */
class RewriteFeed
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.rewrites';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every contributor's rewrites.
     *
     * @return list<array<string, mixed>>
     */
    public function rewrites(): array
    {
        $rewrites = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $contributor) {
            if ($contributor instanceof RewriteFeedContributor) {
                $rewrites = [...$rewrites, ...array_values($contributor->rewrites())];
            }
        }

        return $rewrites;
    }
}
