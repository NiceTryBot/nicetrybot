<?php

namespace NiceTry\PrivacyMode;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use App\Support\SettingsNavItem;

/**
 * Privacy mode, as an add-on: a toggle beside the cog that redacts the page for screen sharing, every
 * real domain and origin host swapped for its made-up stand-in and every word in Settings → Privacy
 * hidden. Its script, style and data blocks go in the core's view slots; the stand-ins themselves
 * are the core's (domains.privacy_hostname).
 */
class PrivacyModeServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
        'web' => 'routes/web.php',
    ];

    protected array $slots = [
        ViewSlot::Head->value => 'privacy-mode::head',
        ViewSlot::TopbarActions->value => 'privacy-mode::toggle',
        ViewSlot::BodyEnd->value => 'privacy-mode::body-end',
        ViewSlot::DomainPanels->value => 'privacy-mode::domain-panel',
    ];

    protected function settingsNav(): array
    {
        return [
            new SettingsNavItem('Privacy', 'privacy.edit', order: 60),
        ];
    }
}
