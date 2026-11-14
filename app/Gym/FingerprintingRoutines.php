<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\FingerprintsRoutines;
use App\Models\GymRoutine;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;

/**
 * The gym routines that take a JS fingerprint of their visitors, by whichever add-ons do the
 * fingerprinting. Without any, no routine does.
 */
class FingerprintingRoutines
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'gym.fingerprinting';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Get the ids of the routines whose visitors are fingerprinted.
     *
     * @param  Collection<int, GymRoutine>  $routines
     * @return list<int>
     */
    public function ids(Collection $routines): array
    {
        if ($routines->isEmpty()) {
            return [];
        }

        $sources = array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $source) => $source instanceof FingerprintsRoutines,
        );

        return array_values(array_unique(array_merge([], ...array_map(fn (FingerprintsRoutines $source) => $source->fingerprinting($routines), $sources))));
    }
}
