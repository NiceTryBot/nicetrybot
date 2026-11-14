<?php

namespace App\Gym\Contracts;

use App\Models\GymRoutine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Something that takes a JS fingerprint of the visitors some gym routines take in, so the Rules page can
 * mark the rules that feed them. Tagged in the container under App\Gym\FingerprintingRoutines::TAG.
 */
interface FingerprintsRoutines
{
    /**
     * Get the ids of the routines whose visitors are fingerprinted.
     *
     * @param  Collection<int, GymRoutine>  $routines
     * @return list<int>
     */
    public function fingerprinting(Collection $routines): array;
}
