<?php

namespace App\Addons;

/**
 * Where an installed add-on stands with the marketplace, for the Add-ons page and the switchboard.
 */
enum LicenceState: string
{
    /** A free add-on: no licence needed. */
    case Free = 'free';

    /** A paid add-on with a key that holds, and that the marketplace hasn't revoked. */
    case Licensed = 'licensed';

    /** A paid add-on without a key, still inside its trial (or where keys aren't enforced). */
    case Trial = 'trial';

    /** A paid add-on without a key, past its trial: off until a key is entered. */
    case TrialEnded = 'trial-ended';

    /** A paid add-on whose licence the marketplace revoked: off until another key is entered. */
    case Revoked = 'revoked';

    /**
     * Determine if the add-on may run, as far as its licence goes.
     */
    public function allowsRunning(): bool
    {
        return match ($this) {
            self::Free, self::Licensed, self::Trial => true,
            self::TrialEnded, self::Revoked => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Licensed => __('Licensed'),
            self::Trial => __('Trial'),
            self::TrialEnded => __('Trial ended'),
            self::Revoked => __('Licence revoked'),
        };
    }

    /**
     * The Flux badge colour for the state.
     */
    public function color(): string
    {
        return match ($this) {
            self::Free => 'zinc',
            self::Licensed => 'emerald',
            self::Trial => 'amber',
            self::TrialEnded, self::Revoked => 'red',
        };
    }
}
