<?php

namespace Tests\Fixtures\Addons\Example\Gym;

use App\Gym\BaseObstacle;
use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleState;

/**
 * The example add-on's obstacle: the visitor knocks, and the second knock lets them in.
 */
class KnockObstacle extends BaseObstacle
{
    public function key(): string
    {
        return 'knock';
    }

    public function name(): string
    {
        return 'Knock twice';
    }

    public function tagline(): string
    {
        return 'The visitor knocks, and the second knock lets them in.';
    }

    public function icon(): string
    {
        return 'hand-raised';
    }

    public function settings(): array
    {
        return [];
    }

    public function summarize(array $config): string
    {
        return 'Two knocks';
    }

    public function humanSeconds(array $config): float
    {
        return 1;
    }

    public function workHashes(array $config): int
    {
        return 0;
    }

    public function begin(ObstacleState $state, array $config): void
    {
        $state->put('knocks', 0);
    }

    public function viewData(ObstacleState $state, array $config): array
    {
        return ['knocks' => (int) $state->get('knocks')];
    }

    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome
    {
        $state->put('knocks', (int) $state->get('knocks') + 1);

        return $state->get('knocks') >= 2 ? ObstacleOutcome::cleared() : ObstacleOutcome::retry('Knock again.');
    }
}
