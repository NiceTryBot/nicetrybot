<?php

namespace NiceTry\SwarmMode;

use App\Addons\AddonServiceProvider;
use App\Enums\NavGroup;
use App\Support\DoorFields;
use App\Support\NavItem;
use NiceTry\RulePacks\RulePacks;
use NiceTry\SwarmMode\Rules\SwarmDoorField;
use NiceTry\SwarmMode\Rules\SwarmRuleDestinationHandler;
use NiceTry\SwarmMode\Rules\SwarmRuleDestinations;
use NiceTry\SwarmMode\Rules\SwarmRuleField;

/**
 * Swarm Mode, as an add-on: a mode a site or a URL goes into for a while, when several IPs hit errors
 * there at once (a distributed flood, each IP too quiet on its own to trip a rule). A rule over host or
 * path turns it on with the SWARM clause; the `swarm` field says a request falls under it, so other
 * rules can act on everyone there, at the Bouncer's door too. It comes with a rule pack: the swarm rules,
 * switched on (they only turn the mode on), and a Bouncer rule for while it's on, switched off. Its
 * page shows what's in Swarm Mode now and for how long, and turns it on or off by hand.
 */
class SwarmModeServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $ruleFields = [SwarmRuleField::class];

    protected array $ruleDestinations = [SwarmRuleDestinations::class];

    protected array $ruleDestinationHandlers = [SwarmRuleDestinationHandler::class];

    protected array $gymTriggerSources = [SwarmTriggerSource::class];

    protected function nav(): array
    {
        return [
            new NavItem('Swarm Mode', 'bolt', 'swarm-mode.index', order: 56, current: ['swarm-mode.*'], test: 'nav-swarm-mode', group: NavGroup::Verify),
        ];
    }

    protected function registerAddon(): void
    {
        $this->tag([SwarmModePack::class], RulePacks::TAG);
        $this->tag([SwarmDoorField::class], DoorFields::TAG);
    }
}
