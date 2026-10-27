<?php

namespace NiceTry\RulePacks\Support;

use App\Gym\Contracts\RoutineTemplateSource;
use App\Support\RuleTemplateSource;
use NiceTry\RulePacks\RulePack;
use NiceTry\RulePacks\RulePacks;

/**
 * The packs, a piece at a time, in the core's template galleries: each pack's first rule among the
 * starter rules (when it parses with the fields installed now), and each routine that needs none of a
 * pack's rules among the starter routines. A template is a starting point, not an install, so the
 * pack page doesn't count what's made from one.
 */
class PackTemplates implements RoutineTemplateSource, RuleTemplateSource
{
    public function __construct(private RulePacks $packs)
    {
        //
    }

    public function ruleTemplates(): array
    {
        $templates = [];

        foreach ($this->packs->all() as $pack) {
            $key = array_key_first($pack->rules());

            if ($key !== null && $pack->unavailableBecause($key) === null) {
                $templates[$this->key($pack, $key)] = $pack->rules()[$key];
            }
        }

        return $templates;
    }

    public function routineTemplates(): array
    {
        $templates = [];

        foreach ($this->packs->all() as $pack) {
            foreach ($pack->routines() as $key => $routine) {
                if ($routine['rules'] === []) {
                    $templates[$this->key($pack, $key)] = [
                        'name' => __($routine['name']),
                        'blurb' => __($routine['blurb']),
                        'icon' => $routine['icon'],
                        'match_all' => $routine['match_all'],
                        'conditions' => $routine['conditions'],
                        'obstacles' => $routine['obstacles'],
                        'exit' => $routine['exit'],
                        'pass_seconds' => $routine['pass_seconds'],
                    ];
                }
            }
        }

        return $templates;
    }

    private function key(RulePack $pack, string $item): string
    {
        return $pack->key().'-'.$item;
    }
}
