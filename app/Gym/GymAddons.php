<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\GymAddon;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;

/**
 * The add-ons packages have tagged into the gym. Without any, the gym is exactly the core's.
 */
class GymAddons
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'gym.addons';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<GymAddon>
     */
    public function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $addon) => $addon instanceof GymAddon,
        ));
    }

    /**
     * Get every add-on's addition to a visitor's page, in the order they were tagged.
     */
    public function pageHtml(GymVisit $visit): HtmlString
    {
        return new HtmlString(implode('', array_map(fn (GymAddon $addon) => $addon->pageHtml($visit)?->toHtml() ?? '', $this->all())));
    }
}
