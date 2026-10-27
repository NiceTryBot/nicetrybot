<?php

namespace App\Data;

/**
 * A plain-English request turned into a rule: its name and script, and the gym routine its clients go
 * through, picked from the team's (routineId) or named as a new one (routineName). Neither when the
 * request doesn't ask for the gym.
 */
readonly class TranslatedRule
{
    public function __construct(
        public string $name,
        public string $source,
        public ?int $routineId = null,
        public ?string $routineName = null,
        public ?string $note = null,
    ) {
        //
    }
}
