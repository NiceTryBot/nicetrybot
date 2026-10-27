<?php

namespace App\Gym;

use App\Models\GymSession;

/**
 * One attempt a visitor made at an obstacle, as the gym judged and saved it, for the packages
 * listening (App\Gym\AttemptListeners).
 */
final readonly class ObstacleAttempt
{
    /**
     * @param  array<string, mixed>  $before  The obstacle's data before the attempt.
     * @param  array<string, mixed>  $after  The obstacle's data after it, as saved.
     * @param  string|null  $clientIp  The IP the attempt came from, as the edge saw it, else the IP the visitor entered from.
     */
    public function __construct(
        public GymSession $session,
        public GymStep $step,
        public array $before,
        public array $after,
        public ObstacleOutcome $outcome,
        public ?string $clientIp,
    ) {
        //
    }
}
