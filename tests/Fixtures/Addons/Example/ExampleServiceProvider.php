<?php

namespace Tests\Fixtures\Addons\Example;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use App\Support\SettingsNavItem;
use App\Support\WorkspaceMenuItem;
use Tests\Fixtures\Addons\Example\Activity\BookmarkMarks;
use Tests\Fixtures\Addons\Example\Auth\BookmarkAccess;
use Tests\Fixtures\Addons\Example\Edge\BookmarkHits;
use Tests\Fixtures\Addons\Example\Edge\BookmarkLockdowns;
use Tests\Fixtures\Addons\Example\Edge\BookmarkTags;
use Tests\Fixtures\Addons\Example\Gym\AttemptLog;
use Tests\Fixtures\Addons\Example\Gym\KnockObstacle;
use Tests\Fixtures\Addons\Example\Models\Bookmark;
use Tests\Fixtures\Addons\Example\Rules\BookmarkField;
use Tests\Fixtures\Addons\Example\Rules\BookmarkTemplates;
use Tests\Fixtures\Addons\Example\Support\BookmarkedComponents;

/**
 * An add-on for the core's tests, registered by the tests that need it: a view in every slot, two in
 * the head, a settings page between the core's and a cog menu link between the core's (with a badge
 * that flags the cog while example.alert is on), a gym obstacle and an attempt listener, and a model
 * (bookmarks) with a policy, tags in the edge's capture feed, lockdowns in its lockdown feed (a
 * bookmark of /locked holds its domain), rows on a domain, Livewire components
 * it names, a rule field that reads them, a starter rule and gym routine, bookmarks the log import
 * keeps beside requests, and marks and buttons on Activity's requests.
 */
class ExampleServiceProvider extends AddonServiceProvider
{
    protected array $routes = [
        'web' => 'routes/web.php',
    ];

    protected array $obstacles = [KnockObstacle::class];

    protected array $attemptListeners = [AttemptLog::class];

    protected array $policies = [Bookmark::class => BookmarkAccess::class];

    protected array $captureFeed = [BookmarkTags::class];

    protected array $lockdownFeed = [BookmarkLockdowns::class];

    protected array $domainRecords = [Bookmark::class];

    protected array $livewireComponentSources = [BookmarkedComponents::class];

    protected array $ruleFields = [BookmarkField::class];

    protected array $ruleTemplates = [BookmarkTemplates::class];

    protected array $routineTemplates = [BookmarkTemplates::class];

    protected array $edgeLineAddons = [BookmarkHits::class];

    protected array $requestAddons = [BookmarkMarks::class];

    protected array $slots = [
        ViewSlot::Head->value => ['example::head', 'example::head-too'],
        ViewSlot::TopbarActions->value => 'example::topbar',
        ViewSlot::BodyEnd->value => 'example::body-end',
        ViewSlot::DomainPanels->value => 'example::domain-panel',
        ViewSlot::RulesGallery->value => 'example::rules-gallery',
        ViewSlot::DomainsActions->value => 'example::domains-action',
        ViewSlot::DomainsSections->value => 'example::domains-section',
    ];

    protected function settingsNav(): array
    {
        return [
            new SettingsNavItem('Example settings', 'example.settings', order: 45),
        ];
    }

    protected function workspaceMenu(): array
    {
        return [
            new WorkspaceMenuItem('Example switch', 'bolt', 'example.settings', order: 15, badge: fn (): ?string => config('example.alert') ? 'On' : null, badgeColor: 'amber', alert: true, test: 'nav-example'),
        ];
    }
}
