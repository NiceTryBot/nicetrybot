<?php

namespace App\Gym\Contracts;

use App\Gym\ObstacleAttempt;

/**
 * Something a package wants to know about every attempt a visitor makes at an obstacle, such as
 * keeping the answers they gave. Listeners are tagged in the container under
 * App\Gym\AttemptListeners::TAG and run in turn, right after the attempt is saved and before the
 * visitor gets their answer, so they must be quick.
 */
interface AttemptListener
{
    /**
     * Hear about one attempt, once the gym has judged and saved it.
     */
    public function attempted(ObstacleAttempt $attempt): void;
}
