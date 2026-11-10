<?php

namespace App\Support;

use App\Enums\RequestAction;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A badge an add-on puts in a request's Action cell on Activity, beside the core's (or in its place, for
 * a request that simply passed or that the add-on itself refused), with an optional line under it.
 */
final readonly class ActionBadge
{
    /**
     * @param  bool  $replacesPassed  Show this instead of the core's Passed badge, when the request passed.
     * @param  bool  $replacesBlocked  Show this instead of the core's Blocked badge, when the request was blocked.
     */
    public function __construct(
        public Htmlable $badge,
        public ?Htmlable $note = null,
        public bool $replacesPassed = true,
        public bool $replacesBlocked = false,
    ) {
        //
    }

    /**
     * Determine if one of a request's add-on badges takes the place of the core's badge for its action.
     *
     * @param  iterable<self>  $badges
     */
    public static function replaceCore(iterable $badges, RequestAction $action): bool
    {
        foreach ($badges as $badge) {
            if (match ($action) {
                RequestAction::Passed => $badge->replacesPassed,
                RequestAction::Blocked => $badge->replacesBlocked,
                default => false,
            }) {
                return true;
            }
        }

        return false;
    }
}
