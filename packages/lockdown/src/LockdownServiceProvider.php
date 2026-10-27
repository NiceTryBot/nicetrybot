<?php

namespace NiceTry\Lockdown;

use App\Addons\AddonServiceProvider;
use App\Models\Team;
use App\Support\WorkspaceMenuItem;
use NiceTry\Lockdown\Edge\EngagedLockdowns;
use NiceTry\Lockdown\Mcp\GetLockdownStatus;
use NiceTry\Lockdown\Mcp\LiftLockdown;
use NiceTry\Lockdown\Mcp\LockDownDomains;
use NiceTry\Lockdown\Mcp\ReleaseDomains;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Lockdown\Policies\LockdownPolicy;

/**
 * Lockdown, as an add-on: the emergency switch that puts a team's locked sites behind a plain
 * maintenance page at the edge, so nothing reaches their origins. The team picks what's locked
 * (whole shared origins while something keeps them, or sites one by one), lets admins through by IP
 * or with a signed pass, and can write its own page. Engaged lockdowns go to the edge in the core's
 * lockdown feed; the Lockdown link is in the cog menu, which shows a dot while it's on. AI agents get
 * tools on the MCP server to see the lockdown, lock sites down, release them and lift it.
 */
class LockdownServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $policies = [
        Lockdown::class => LockdownPolicy::class,
    ];

    protected array $lockdownFeed = [EngagedLockdowns::class];

    protected array $mcpTools = [
        GetLockdownStatus::class,
        LockDownDomains::class,
        ReleaseDomains::class,
        LiftLockdown::class,
    ];

    protected function bootAddon(): void
    {
        Team::resolveRelationUsing('lockdown', fn (Team $team) => $team->hasOne(Lockdown::class));
    }

    protected function workspaceMenu(): array
    {
        return [
            new WorkspaceMenuItem('Lockdown', 'lock-closed', 'lockdown.index', order: 30, badge: fn (): ?string => auth()->user()?->currentTeam?->lockdown()->whereNotNull('engaged_at')->exists() ? __('On') : null, badgeColor: 'red', alert: true, test: 'nav-lockdown'),
        ];
    }
}
