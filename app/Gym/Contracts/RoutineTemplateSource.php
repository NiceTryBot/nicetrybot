<?php

namespace App\Gym\Contracts;

use App\Enums\GymExit;

/**
 * Starter gym routines a package adds to the gym's template gallery, after the core's. Sources are
 * tagged in the container under App\Gym\RoutineTemplates::TAG.
 */
interface RoutineTemplateSource
{
    /**
     * The templates, keyed by a slug unique among every template (a key already taken is left out), in
     * the shape App\Gym\RoutineTemplates::all() gives them. A routine naming an obstacle that isn't
     * installed skips it, so a template should only use the core's or its own add-on's.
     *
     * @return array<string, array{name: string, blurb: string, icon: string, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{type: string, config: array<string, mixed>, escalate: string|null}>, exit: GymExit, pass_seconds: int}>
     */
    public function routineTemplates(): array;
}
