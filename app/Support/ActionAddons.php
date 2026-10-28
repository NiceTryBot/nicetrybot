<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\Team;
use Illuminate\Contracts\Container\Container;

/**
 * The action add-ons packages have tagged in (App\Support\ActionAddon). Without any, Activity's Action
 * column is exactly the core's.
 */
class ActionAddons
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'activity.action-addons';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every add-on's badges for a page of requests, keyed like the ids given, in the order the add-ons
     * were tagged. A request no add-on has a badge for gets an empty list.
     *
     * @param  array<int, int>  $requestIds
     * @return array<int, list<ActionBadge>>
     */
    public function badges(Team $team, array $requestIds): array
    {
        $badges = array_map(fn () => [], $requestIds);

        foreach ($requestIds === [] ? [] : $this->all() as $addon) {
            foreach ($addon->badges($team, $requestIds) as $key => $badge) {
                if ($badge !== null && array_key_exists($key, $badges)) {
                    $badges[$key][] = $badge;
                }
            }
        }

        return $badges;
    }

    /**
     * @return list<ActionAddon>
     */
    private function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $addon) => $addon instanceof ActionAddon,
        ));
    }
}
