<?php

namespace App\Gym;

use App\Enums\GymExit;
use App\Models\GymSession;

/**
 * A visitor's pipeline as it stands right now: the obstacles from every routine they matched (each
 * obstacle type once, first routine wins), escalations slotted in after the obstacle that raised them,
 * the strictest exit, and the shortest pass.
 */
readonly class GymPlan
{
    /**
     * @param  list<GymStep>  $steps
     * @param  list<string>  $routineNames
     */
    public function __construct(
        public array $steps,
        public GymExit $exit,
        public int $passSeconds,
        public array $routineNames = [],
    ) {
        //
    }

    /**
     * Get the first obstacle the visitor hasn't cleared, or null when they've cleared them all.
     */
    public function currentStep(GymSession $session): ?GymStep
    {
        foreach ($this->steps as $step) {
            if (! $session->state($step->key)->isCleared()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Determine if the plan already has an obstacle of a type.
     */
    public function has(string $obstacleKey): bool
    {
        foreach ($this->steps as $step) {
            if ($step->obstacle->key() === $obstacleKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estimate the seconds a person spends clearing the whole plan.
     */
    public function humanSeconds(): float
    {
        return array_sum(array_map(fn (GymStep $step) => $step->obstacle->humanSeconds($step->config), $this->steps));
    }

    /**
     * Estimate the hashes a visitor's machine burns clearing the whole plan.
     */
    public function workHashes(): int
    {
        return array_sum(array_map(fn (GymStep $step) => $step->obstacle->workHashes($step->config), $this->steps));
    }
}
