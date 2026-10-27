<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\Obstacle;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;

/**
 * The obstacles the gym can use, keyed by Obstacle::key(), in library order: the core's, from the
 * explicit list in config/gym.php, then those installed add-ons tag in (TAG). Adding a class to the
 * codebase never puts it in front of visitors by itself.
 */
class ObstacleRegistry
{
    /**
     * The container tag add-ons' obstacles register under.
     */
    public const string TAG = 'gym.obstacles';

    /**
     * @var array<string, Obstacle>
     */
    private array $obstacles = [];

    /**
     * @param  iterable<Obstacle>  $obstacles
     */
    public function __construct(iterable $obstacles = [])
    {
        foreach ($obstacles as $obstacle) {
            $this->register($obstacle);
        }
    }

    /**
     * Add an obstacle to the gym, and give its views their namespace (obstacle-{key}::…).
     *
     * @throws InvalidArgumentException When another obstacle already uses its key.
     */
    public function register(Obstacle $obstacle): void
    {
        if (isset($this->obstacles[$obstacle->key()])) {
            throw new InvalidArgumentException("Two gym obstacles use the key [{$obstacle->key()}].");
        }

        if ($obstacle instanceof BaseObstacle) {
            View::replaceNamespace($obstacle->viewNamespace(), $obstacle->viewPath());
        }

        $this->obstacles[$obstacle->key()] = $obstacle;
    }

    /**
     * Get every registered obstacle, leaving out those of add-ons turned off.
     *
     * @return array<string, Obstacle>
     */
    public function all(): array
    {
        $switchboard = app(AddonSwitchboard::class);

        return array_filter($this->obstacles, fn (Obstacle $obstacle): bool => $switchboard->allows($obstacle));
    }

    /**
     * Get the obstacle with a key, or null when no obstacle (any longer) has it, or its add-on is off:
     * routines then skip it, as they do one that was uninstalled.
     */
    public function find(string $key): ?Obstacle
    {
        $obstacle = $this->obstacles[$key] ?? null;

        return $obstacle !== null && app(AddonSwitchboard::class)->allows($obstacle) ? $obstacle : null;
    }
}
