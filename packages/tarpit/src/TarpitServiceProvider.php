<?php

namespace NiceTry\Tarpit;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\NavItem;
use NiceTry\Tarpit\Activity\TarpitActivity;
use NiceTry\Tarpit\Edge\TarpitHolds;
use NiceTry\Tarpit\Edge\TarpitList;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;
use NiceTry\Tarpit\Policies\TarpitPolicy;
use NiceTry\Tarpit\Rules\TarpitField;
use NiceTry\Tarpit\Rules\TarpitTemplates;

/**
 * Tarpits, as an add-on: make the worst bots wait at the edge. A team sets up tarpits (how long each
 * try waits, how many requests may wait at once, what happens after) and sends clients in from the
 * Rules page, by the gym's no-shows, or by the edge's own count of 404s and bursts. Its tarpits go to
 * the edge in the core's tarpit feed; what they did to each request comes back on the log lines, is
 * kept beside the request, shows on Activity as a Tarpit badge, and can be searched for there (and
 * tested in rules) with the tarpit and tarpit.outcome fields.
 */
class TarpitServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $policies = [
        Tarpit::class => TarpitPolicy::class,
    ];

    protected array $tarpitFeed = [TarpitList::class];

    protected array $edgeLineAddons = [TarpitHolds::class];

    protected array $actionAddons = [TarpitActivity::class];

    protected array $requestAddons = [TarpitActivity::class];

    protected array $ruleTemplates = [TarpitTemplates::class];

    protected array $ruleFields = [TarpitField::class];

    protected array $slots = [
        ViewSlot::RulePanels->value => 'tarpit::rule-panels',
    ];

    protected function bootAddon(): void
    {
        Team::resolveRelationUsing('tarpits', fn (Team $team) => $team->hasMany(Tarpit::class));
        EdgeRequest::resolveRelationUsing('tarpitHold', fn (EdgeRequest $request) => $request->hasOne(TarpitHold::class));
    }

    protected function nav(): array
    {
        return [
            new NavItem('Tarpits', 'clock', 'tarpits.index', order: 95, current: ['tarpits.*']),
        ];
    }
}
