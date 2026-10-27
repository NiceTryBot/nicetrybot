<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The edge-line add-ons packages have tagged in. Without any, the import stores each line as a
 * request and nothing beside it but its Livewire call.
 */
class EdgeLineAddons
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'edge.line-addons';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<EdgeLineAddon>
     */
    public function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $addon) => $addon instanceof EdgeLineAddon,
        ));
    }
}
