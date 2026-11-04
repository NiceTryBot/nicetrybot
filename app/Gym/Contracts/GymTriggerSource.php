<?php

namespace App\Gym\Contracts;

/**
 * Something that has the edge ask the gym check about visitors on some hosts, whatever routines want:
 * those carrying a gym cookie (a package whose own cookie stands for a visitor it means to see), and,
 * with rules, those whose request matches one of the rules' single-request WHERE (EdgeExpression), the
 * ids of which the edge names in X-NiceTry-Rules. Sources are tagged in the container under
 * App\Gym\GymTriggerSources::TAG.
 */
interface GymTriggerSource
{
    /**
     * The hosts (as the edge serves them, lower case) to ask about, the path glob on them, and any rules
     * (an EdgeRule's id with its EdgeExpression) whose matching requests to ask about too.
     *
     * @return list<array{hosts: list<string>, path: string, rules?: list<array{id: int, expr: array<string, mixed>}>}>
     */
    public function cookieTriggers(): array;
}
