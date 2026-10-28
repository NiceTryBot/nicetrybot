<?php

namespace App\Gym\Contracts;

use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleSetting;
use App\Gym\ObstacleState;

/**
 * A pluggable gym obstacle: one handler in a visitor's pipeline. The gym owns ordering, stacking,
 * escalation and exits; an obstacle only describes its settings, sets itself up for a visitor, draws
 * its page and judges attempts. Register the core's in config/gym.php and an add-on's in its
 * provider's $obstacles.
 *
 * Config arrays passed in have already been through configure(), so every setting is present and
 * within bounds. State is the obstacle's own scratch space for one visitor, saved between requests.
 */
interface Obstacle
{
    /**
     * Get the stable key routines store, e.g. "proof-of-work". Never change it once routines use it.
     */
    public function key(): string;

    /**
     * Get the obstacle's display name.
     */
    public function name(): string;

    /**
     * Get one line on what the visitor goes through, shown in the obstacle library.
     */
    public function tagline(): string;

    /**
     * Get the Heroicon shown with the obstacle.
     */
    public function icon(): string;

    /**
     * Get the settings an admin can tune, in display order.
     *
     * @return list<ObstacleSetting>
     */
    public function settings(): array;

    /**
     * Fill in defaults and clamp a stored or edited config to the settings' bounds.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, int|string|bool|list<string>>
     */
    public function configure(array $config): array;

    /**
     * Sum a config up in a few words, e.g. "18 bits · about 0.2 s on a laptop".
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    public function summarize(array $config): string;

    /**
     * Estimate the seconds a person spends on the obstacle.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    public function humanSeconds(array $config): float;

    /**
     * Estimate the SHA-256 hashes a visitor's machine burns clearing the obstacle.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    public function workHashes(array $config): int;

    /**
     * Say what makes this obstacle flag a visitor as automated, or null if it never does. Only
     * obstacles that can flag offer escalation.
     */
    public function flagsWhen(): ?string;

    /**
     * Set the obstacle up the first time a visitor reaches it: issue a challenge, draw a code.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    public function begin(ObstacleState $state, array $config): void;

    /**
     * Get the Blade view that draws the obstacle for the visitor.
     */
    public function view(): string;

    /**
     * Get the data the view needs.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     * @return array<string, mixed>
     */
    public function viewData(ObstacleState $state, array $config): array;

    /**
     * Judge one attempt: clear it, keep it going, or ask for another try.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     * @param  array<string, mixed>  $input
     */
    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome;
}
