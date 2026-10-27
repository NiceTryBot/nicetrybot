<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The list the edge's capture module polls, put together from every contributor tagged in (add-ons'
 * captures and tags). Both lists the edge reads are always there, empty when nothing fills them, and
 * the keys are sorted, so the same routes always make the same body and ETag.
 */
class CaptureFeed
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.capture-feed';

    /**
     * The lists the edge reads.
     *
     * @var list<string>
     */
    public const array LISTS = ['captures', 'tags'];

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function payload(): array
    {
        $hosts = new CaptureFeedHosts;
        $payload = array_fill_keys(self::LISTS, []);

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $contributor) {
            if (! $contributor instanceof CaptureFeedContributor) {
                continue;
            }

            foreach ($contributor->contribute($hosts) as $list => $entries) {
                $payload[$list] = [...$payload[$list] ?? [], ...$entries];
            }
        }

        ksort($payload);

        return $payload;
    }
}
