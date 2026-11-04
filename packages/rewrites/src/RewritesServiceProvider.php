<?php

namespace NiceTry\Rewrites;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use App\Models\Team;
use App\Support\WorkspaceMenuItem;
use NiceTry\Rewrites\Edge\RewriteList;
use NiceTry\Rewrites\Models\ResponseRewrite;
use NiceTry\Rewrites\Policies\ResponseRewritePolicy;

/**
 * Response rewrites, as an add-on: the edge changes text in what a team's origins send before visitors
 * see it, like every "foo" becoming "bar". A rewrite covers every domain of the team or one of them,
 * and HTML pages only or any text response. Enabled rewrites go to the edge in the core's rewrite
 * feed; the Rewrites link is in the cog menu, and each domain's page has a Rewrites panel for its own.
 */
class RewritesServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'team' => 'routes/team.php',
    ];

    protected array $policies = [
        ResponseRewrite::class => ResponseRewritePolicy::class,
    ];

    protected array $rewriteFeed = [RewriteList::class];

    protected array $domainRecords = [ResponseRewrite::class];

    protected array $slots = [
        ViewSlot::DomainPanels->value => 'rewrites::domain-panel',
    ];

    protected function bootAddon(): void
    {
        Team::resolveRelationUsing('responseRewrites', fn (Team $team) => $team->hasMany(ResponseRewrite::class));
    }

    protected function workspaceMenu(): array
    {
        return [
            new WorkspaceMenuItem('Rewrites', 'arrows-right-left', 'rewrites.index', order: 15, test: 'nav-rewrites'),
        ];
    }
}
