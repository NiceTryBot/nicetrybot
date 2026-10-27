<?php

namespace NiceTry\RulePacks;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use NiceTry\RulePacks\Packs\AiCrawlers;
use NiceTry\RulePacks\Packs\BrowserImpostors;
use NiceTry\RulePacks\Packs\FormScripts;
use NiceTry\RulePacks\Packs\PhpExploits;
use NiceTry\RulePacks\Packs\Scanners;
use NiceTry\RulePacks\Support\PackTemplates;

/**
 * Rule packs, as an add-on: curated, versioned sets of generic rules, with gym routines for the
 * clients they tag, that a team installs in one go from the Rules page (rules.packs, linked below its
 * template gallery). Installing never sends anyone anywhere: the rules only tag, and the routines start
 * switched off. Which pack and version each rule and routine came from is kept in rule_pack_installs.
 * Each pack's first rule, and its routines that need none of its rules, join the core's template
 * galleries too. Packs are tagged under RulePacks::TAG, so another add-on can bring its own.
 */
class RulePacksServiceProvider extends AddonServiceProvider
{
    /**
     * The packs this add-on brings, in the order the page lists them.
     *
     * @var list<class-string<RulePack>>
     */
    public const array PACKS = [
        Scanners::class,
        PhpExploits::class,
        AiCrawlers::class,
        BrowserImpostors::class,
        FormScripts::class,
    ];

    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $ruleTemplates = [PackTemplates::class];

    protected array $routineTemplates = [PackTemplates::class];

    protected array $slots = [
        ViewSlot::RulesGallery->value => 'rule-packs::rules-gallery',
    ];

    protected function registerAddon(): void
    {
        $this->tag(self::PACKS, RulePacks::TAG);
    }
}
