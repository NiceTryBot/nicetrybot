<?php

namespace Tests\Fixtures\Addons\Example\Gym;

use App\Gym\Contracts\AttemptListener;
use App\Gym\ObstacleAttempt;
use App\Models\GymSession;

/**
 * The example add-on's attempt listener: it notes each attempt it hears about, with the session as the
 * database held it then. Tests bind it as a singleton to read what it heard.
 */
class AttemptLog implements AttemptListener
{
    /**
     * @var list<array{attempt: ObstacleAttempt, saved: array<string, mixed>}>
     */
    public array $heard = [];

    public function attempted(ObstacleAttempt $attempt): void
    {
        $this->heard[] = ['attempt' => $attempt, 'saved' => GymSession::query()->findOrFail($attempt->session->id)->state($attempt->step->key)->data];
    }
}
