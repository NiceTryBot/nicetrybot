<?php

namespace App\Addons;

use Illuminate\Container\Attributes\Singleton;

/**
 * The add-ons installed in this installation, in the order their providers registered, and which
 * add-on brought each class it tagged into the core. Each AddonServiceProvider adds its own; nothing
 * has to list them. This is what the deploy shipped; which of them are on is the switchboard's
 * (AddonSwitchboard), since that can change between requests.
 */
#[Singleton]
class Addons
{
    /**
     * @var array<string, Addon>
     */
    private array $addons = [];

    /**
     * The slug of the add-on that brought each class, by class.
     *
     * @var array<class-string, string>
     */
    private array $owners = [];

    public function add(Addon $addon): void
    {
        $this->addons[$addon->slug] = $addon;
    }

    /**
     * @return list<Addon>
     */
    public function all(): array
    {
        return array_values($this->addons);
    }

    public function find(string $slug): ?Addon
    {
        return $this->addons[$slug] ?? null;
    }

    /**
     * Determine if an add-on is installed.
     */
    public function has(string $slug): bool
    {
        return isset($this->addons[$slug]);
    }

    /**
     * Record that an add-on brought these classes, so they're left out while it's off. A class
     * another add-on claimed first stays that add-on's.
     *
     * @param  iterable<class-string>  $classes
     */
    public function claim(string $slug, iterable $classes): void
    {
        foreach ($classes as $class) {
            $this->owners[$class] ??= $slug;
        }
    }

    /**
     * The slug of the add-on that brought a class, or null when it's the core's.
     */
    public function ownerOf(string $class): ?string
    {
        return $this->owners[$class] ?? null;
    }
}
