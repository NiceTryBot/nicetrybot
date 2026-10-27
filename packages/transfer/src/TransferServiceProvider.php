<?php

namespace NiceTry\Transfer;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;

/**
 * Transfer, as an add-on: moves a shared origin (with its sites, groups, sites' own protection modes and
 * origin secret) and its team's rules and gym routines from one NiceTryBot to another, such as from the
 * hosted service to a panel of your own. Two ways: the new panel pulls it from the old one with an API
 * token (the old one answers on transfer/v1/origins, behind the same token check as the MCP server), or
 * a team admin downloads a transfer file on the old panel and uploads it on the new one. Both start on
 * the Transfer page, linked from the Domains page. Importing again updates what's there; nothing is
 * duplicated.
 *
 * It needs the Origins add-on on both sides, since what it moves is an origin: without it, the API
 * answers 404 and the Transfer page says so.
 */
class TransferServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
        'web' => 'routes/api.php',
    ];

    protected array $slots = [
        ViewSlot::DomainsActions->value => 'transfer::domains-button',
    ];
}
