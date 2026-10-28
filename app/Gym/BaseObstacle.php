<?php

namespace App\Gym;

use App\Gym\Contracts\Obstacle;
use ReflectionClass;

/**
 * Shared plumbing for obstacles: configs come from the settings list, and the view lives beside the
 * class, as gym/obstacles/<Vendor>/<Name>/resources/views/obstacle.blade.php, under the namespace
 * obstacle-{key}:: that the registry gives it.
 */
abstract class BaseObstacle implements Obstacle
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int|string|bool|list<string>>
     */
    public function configure(array $config): array
    {
        $configured = [];

        foreach ($this->settings() as $setting) {
            $configured[$setting->key] = $setting->normalize($config[$setting->key] ?? $setting->default);
        }

        return $configured;
    }

    /**
     * Read a setting that holds one value (a range, choice, toggle or text) from a configured config.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    protected function scalar(array $config, string $key): int|string|bool
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? $value : '';
    }

    public function flagsWhen(): ?string
    {
        return null;
    }

    /**
     * Get the obstacle's own directory, the one its class file sits in.
     */
    public function basePath(): string
    {
        return dirname((string) new ReflectionClass($this)->getFileName());
    }

    public function viewNamespace(): string
    {
        return 'obstacle-'.$this->key();
    }

    public function viewPath(): string
    {
        return $this->basePath().'/resources/views';
    }

    public function view(): string
    {
        return $this->viewNamespace().'::obstacle';
    }

    /**
     * Get one of the obstacle's settings by key.
     */
    protected function setting(string $key): ObstacleSetting
    {
        return collect($this->settings())->firstOrFail(fn (ObstacleSetting $setting) => $setting->key === $key);
    }
}
