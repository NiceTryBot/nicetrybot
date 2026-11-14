<?php

namespace NiceTry\Honeypots;

use App\Addons\AddonServiceProvider;
use App\Enums\NavGroup;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\NavItem;
use NiceTry\Honeypots\Activity\HoneypotActivity;
use NiceTry\Honeypots\Edge\HoneypotHits;
use NiceTry\Honeypots\Edge\HoneypotList;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotHit;
use NiceTry\Honeypots\Models\HoneypotTrap;
use NiceTry\Honeypots\Policies\HoneypotPolicy;
use NiceTry\Honeypots\Policies\HoneypotTrapPolicy;

/**
 * Honeypots, as an add-on: a team points traps (path patterns like api/* or admin/*, on all its domains
 * or one) at a honeypot of its own, such as a Galah server, and the edge answers those paths' 404s from
 * the honeypot instead of the site. Pages the site serves are never touched, and the edge only does it
 * after everything else in front of the origin (fingerprints, gym, tarpits, verdicts). Its traps go to
 * the edge in the core's honeypot feed; what each honeypot did comes back on the log lines, is kept
 * beside the request, and shows on Activity as a Honeypot badge.
 */
class HoneypotsServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $policies = [
        Honeypot::class => HoneypotPolicy::class,
        HoneypotTrap::class => HoneypotTrapPolicy::class,
    ];

    protected array $honeypotFeed = [HoneypotList::class];

    protected array $edgeLineAddons = [HoneypotHits::class];

    protected array $actionAddons = [HoneypotActivity::class];

    protected array $requestAddons = [HoneypotActivity::class];

    protected array $domainRecords = [HoneypotTrap::class];

    protected function bootAddon(): void
    {
        Team::resolveRelationUsing('honeypots', fn (Team $team) => $team->hasMany(Honeypot::class));
        Team::resolveRelationUsing('honeypotTraps', fn (Team $team) => $team->hasMany(HoneypotTrap::class));
        EdgeRequest::resolveRelationUsing('honeypotHit', fn (EdgeRequest $request) => $request->hasOne(HoneypotHit::class));
    }

    protected function nav(): array
    {
        return [
            new NavItem('Honeypots', 'bug-ant', 'honeypots.index', order: 97, current: ['honeypots.*'], group: NavGroup::Timeouts),
        ];
    }
}
