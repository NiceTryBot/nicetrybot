<?php

namespace NiceTry\Transfer;

use App\Addons\Addons;
use App\Addons\AddonSwitchboard;
use NiceTry\Origins\Models\Origin;

/**
 * Whether the Origins add-on is here and on, since a transfer is an origin. It's paid and kept out of
 * the public release, so Transfer never touches its classes without asking this first.
 */
final class OriginsAddon
{
    public const string SLUG = 'origins';

    public static function isOn(): bool
    {
        return class_exists(Origin::class)
            && app(Addons::class)->has(self::SLUG)
            && app(AddonSwitchboard::class)->isOn(self::SLUG);
    }
}
