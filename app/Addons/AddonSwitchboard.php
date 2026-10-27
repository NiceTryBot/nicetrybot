<?php

namespace App\Addons;

use App\Models\AddonSwitch;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Which installed add-ons are on, for this request. An add-on's code ships with the deploy, so every
 * server registers it; what an administrator turns off is kept in the addon_switches table and the
 * shared cache, and read once per request, so it takes effect on every server (each Lambda included)
 * without a deploy. An add-on that's off keeps its tables, rule fields, policies and data duties
 * (retention and erasure), and stops everything else: its pages, links, slots, gym obstacles, feeds
 * to the edge and hooks into the core's pages.
 *
 * A paid add-on without a valid licence (Licences: past its trial, or revoked) is off the same way,
 * until a key is entered on the Add-ons page.
 *
 * Scoped, so under Octane each request starts over and sees a change made on another server.
 */
#[Scoped]
class AddonSwitchboard
{
    public const string CACHE_KEY = 'addons:off';

    /**
     * @var list<string>|null
     */
    private ?array $off = null;

    public function __construct(private Addons $addons, private Licences $licences)
    {
        //
    }

    /**
     * The slugs of the add-ons that are off: turned off by an administrator (installed or not), or
     * paid and unlicensed.
     *
     * @return list<string>
     */
    public function off(): array
    {
        return array_values(array_unique([...($this->off ??= $this->load()), ...$this->licences->unlicensed()]));
    }

    /**
     * Determine if an add-on is on. Anything that isn't an add-on's (a null slug: the core's) is.
     */
    public function isOn(?string $slug): bool
    {
        return $slug === null || ! in_array($slug, $this->off(), true);
    }

    /**
     * Determine if a service may be used: it's the core's, or its add-on is on.
     */
    public function allows(object|string $service): bool
    {
        return $this->isOn($this->addons->ownerOf(is_object($service) ? $service::class : $service));
    }

    /**
     * Leave out the services whose add-on is off, keeping the rest in order, as a container's tagged()
     * gives them.
     *
     * @param  iterable<mixed>  $services
     * @return list<mixed>
     */
    public function active(iterable $services): array
    {
        $active = [];

        foreach ($services as $service) {
            if (! is_object($service) || $this->allows($service)) {
                $active[] = $service;
            }
        }

        return $active;
    }

    /**
     * Read the switches again, on this server and every other, after one changed.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->off = null;
    }

    /**
     * @return list<string>
     */
    private function load(): array
    {
        try {
            /** @var list<string> */
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => AddonSwitch::query()
                ->whereNotNull('turned_off_at')
                ->orderBy('slug')
                ->pluck('slug')
                ->all());
        } catch (QueryException) {
            // Before the table's migrated (a fresh install, or mid-deploy): everything's on.
            return [];
        }
    }
}
