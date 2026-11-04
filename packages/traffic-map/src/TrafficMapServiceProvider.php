<?php

namespace NiceTry\TrafficMap;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use App\Support\NavItem;

class TrafficMapServiceProvider extends AddonServiceProvider
{
    /** @var array{team?: string, gym?: string, web?: string} */
    protected array $routes = ['team' => 'routes/team.php'];

    /** @var array<string, view-string|list<view-string>> */
    protected array $slots = [
        ViewSlot::ActivityMap->value => 'traffic-map::activity-map',
    ];

    /**
     * @return list<NavItem>
     */
    protected function nav(): array
    {
        return [
            new NavItem('Traffic Map', 'map', 'traffic-map.index', order: 45, current: ['traffic-map.*']),
        ];
    }
}
