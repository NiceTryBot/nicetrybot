<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The Livewire component sources packages have tagged in. Without any, a team knows the components
 * seen on its domains.
 */
class LivewireComponentSources
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'livewire.component-sources';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<LivewireComponentSource>
     */
    public function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $source) => $source instanceof LivewireComponentSource,
        ));
    }
}
