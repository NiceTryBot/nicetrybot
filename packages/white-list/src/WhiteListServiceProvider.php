<?php

namespace NiceTry\WhiteList;

use App\Addons\AddonServiceProvider;
use App\Support\SettingsNavItem;
use NiceTry\WhiteList\Support\WhitelistAllowed;

/**
 * White List, as an add-on: addresses that never meet an enforcement. An administrator lists IPs and
 * CIDR ranges by hand (Admin → White List), each under a name and a category written X/Y (bot/crawler,
 * office/vpn). A visitor on an enabled entry is never sent to the gym, held in a tarpit, blocked, or
 * dropped in the blackhole — on any site — while still showing up in Activity and reports, so a trusted
 * crawler stays visible.
 *
 * Allowing an address reaches every site on the edge, so it belongs to the installation's
 * administrators: it's an Admin setting, with no per-team page. The Nice Bots add-on, if installed,
 * fills a "Good bots" tab on the page (ViewSlot::WhiteListGoodBots). Turning the add-on off drops its
 * ranges from the allow list on the next request; its entries stay in the table.
 */
class WhiteListServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'web' => 'routes/web.php',
    ];

    protected array $allowedAddresses = [WhitelistAllowed::class];

    protected function adminNav(): array
    {
        return [
            new SettingsNavItem('White List', 'admin.white-list', order: 38),
        ];
    }
}
