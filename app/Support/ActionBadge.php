<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * A badge an add-on puts in a request's Action cell on Activity, beside the core's (or, for a request
 * that simply passed, in its place), with an optional line under it.
 */
final readonly class ActionBadge
{
    /**
     * @param  bool  $replacesPassed  Show this instead of the core's Passed badge, when the request passed.
     */
    public function __construct(
        public Htmlable $badge,
        public ?Htmlable $note = null,
        public bool $replacesPassed = true,
    ) {
        //
    }
}
