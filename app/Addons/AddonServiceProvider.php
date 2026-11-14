<?php

namespace App\Addons;

use App\Enums\ViewSlot;
use App\Gym\AttemptListeners;
use App\Gym\Contracts\AttemptListener;
use App\Gym\Contracts\FrontGate;
use App\Gym\Contracts\GymAddon;
use App\Gym\Contracts\GymCheckHandler;
use App\Gym\Contracts\GymTriggerSource;
use App\Gym\Contracts\Obstacle;
use App\Gym\Contracts\RoutineTemplateSource;
use App\Gym\FrontGates;
use App\Gym\GymAddons;
use App\Gym\GymCheckHandlers;
use App\Gym\GymTriggerSources;
use App\Gym\ObstacleRegistry;
use App\Gym\RoutineTemplates;
use App\Http\Middleware\EnsureAddonIsOn;
use App\Http\Middleware\EnsureTeamMembership;
use App\Mcp\McpTools;
use App\Support\ActionAddon;
use App\Support\ActionAddons;
use App\Support\AdminNav;
use App\Support\AllowedAddresses;
use App\Support\AllowedAddressSource;
use App\Support\CaptureFeed;
use App\Support\CaptureFeedContributor;
use App\Support\CertificateHostnames;
use App\Support\CertificateHostnameSource;
use App\Support\ClientAddon;
use App\Support\ClientAddons;
use App\Support\DomainRecords;
use App\Support\EdgeLineAddon;
use App\Support\EdgeLineAddons;
use App\Support\HoneypotFeed;
use App\Support\HoneypotFeedContributor;
use App\Support\HostnameClaimer;
use App\Support\HostnameClaimers;
use App\Support\LivewireComponentSource;
use App\Support\LivewireComponentSources;
use App\Support\LockdownFeed;
use App\Support\LockdownFeedContributor;
use App\Support\Nav;
use App\Support\NavItem;
use App\Support\RequestAddon;
use App\Support\RequestAddons;
use App\Support\RewriteFeed;
use App\Support\RewriteFeedContributor;
use App\Support\RuleDestinationHandler;
use App\Support\RuleDestinationHandlers;
use App\Support\RuleDestinations;
use App\Support\RuleDestinationSource;
use App\Support\RuleFieldDefinition;
use App\Support\RuleFields;
use App\Support\RuleFilter;
use App\Support\RuleFilters;
use App\Support\RuleTemplates;
use App\Support\RuleTemplateSource;
use App\Support\SettingsNav;
use App\Support\SettingsNavItem;
use App\Support\TarpitFeed;
use App\Support\TarpitFeedContributor;
use App\Support\ViewSlots;
use App\Support\VisitorDataHolder;
use App\Support\VisitorDataHolders;
use App\Support\WorkspaceMenu;
use App\Support\WorkspaceMenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Tool;
use Livewire\Livewire;
use LogicException;
use ReflectionClass;

/**
 * The base of every add-on's service provider, in the manner of Statamic's. An add-on is a Composer
 * package of type nicetry-addon; Laravel discovers its provider, and this wires up the rest by
 * convention, from the package root:
 *
 * - config/{slug}.php merged as config('{slug}')
 * - database/migrations run with the core's
 * - resources/views as {slug}::, and resources/views/livewire as Livewire's {slug}:: pages
 * - the route files in $routes, the core's add-on tags in $gymAddons, $clientAddons and
 *   $visitorData, and the sidebar links nav() returns
 * - the views in $slots, drawn in the core's view slots (App\Enums\ViewSlot), the settings menu
 *   links settingsNav() returns, the Admin menu links adminNav() returns, and the cog menu links
 *   workspaceMenu() returns
 * - the gym obstacles in $obstacles, offered after the core's, and the $attemptListeners told about
 *   every attempt a visitor makes. An obstacle lives in gym/obstacles/<Vendor>/<Name>/ as the core's
 *   do, so the gym's stylesheet builds the classes its views use.
 * - what answers the edge's gym check before the core does ($gymCheckHandlers), and the hosts where
 *   a visitor carrying a gym cookie is asked about at all ($gymTriggerSources)
 * - the $policies for its models, registered with the Gate rather than left to its guessing
 * - its entries in the edge's capture feed ($captureFeed), lockdown feed ($lockdownFeed), tarpit
 *   feed ($tarpitFeed), rewrite feed ($rewriteFeed) and honeypot feed ($honeypotFeed), its
 *   models that point at a domain ($domainRecords, moved with it when www. folds), what names
 *   Livewire components for a team ($livewireComponentSources), what adds a domain for a hostname
 *   the edge hasn't heard of ($hostnameClaimers), and the hostnames of its own the edge may get a
 *   certificate for ($certificateHostnames)
 * - the request fields in $ruleFields, which rule scripts and Activity searches can test after the
 *   core's (each a string-backed enum of App\Support\RuleFieldDefinition cases, joining what it reads
 *   only into the queries that read it)
 * - starter rules and gym routines for the template galleries, after the core's: $ruleTemplates
 *   (App\Support\RuleTemplateSource) and $routineTemplates (App\Gym\Contracts\RoutineTemplateSource)
 * - what it keeps from the edge's log lines beside each request ($edgeLineAddons), and what it shows
 *   about requests on the Activity page ($requestAddons: marks in the list, details and buttons;
 *   $actionAddons: badges in the Action column)
 * - where rules can send their clients, for the Rules page's board: $ruleDestinations
 *   (App\Support\RuleDestinationSource), and the dropdowns that narrow its board and list:
 *   $ruleFilters (App\Support\RuleFilter)
 *
 * Anything else goes in registerAddon() and bootAddon(); tag classes into a registry there with
 * $this->tag(), so they're known as the add-on's.
 *
 * An administrator can turn an installed add-on off (App\Addons\AddonSwitchboard) without a deploy:
 * its routes answer 404 and the core's registries leave out what it brought, on the next request.
 * Its migrations, policies, rule fields, visitor data and domain records stay, so its data is still
 * pruned, erased and moved, and saved rules that test its fields still parse.
 */
abstract class AddonServiceProvider extends ServiceProvider
{
    /**
     * Route files, relative to the package root, by where they're served: 'team' for signed-in pages
     * under a team ({current_team}/…), 'gym' for the visitor's side under the gym's path (no session,
     * CSRF or Livewire, as on customers' domains), 'web' as they are.
     *
     * @var array{team?: string, gym?: string, web?: string}
     */
    protected array $routes = [];

    /**
     * @var list<class-string<GymAddon>>
     */
    protected array $gymAddons = [];

    /**
     * @var list<class-string<ClientAddon>>
     */
    protected array $clientAddons = [];

    /**
     * @var list<class-string<VisitorDataHolder>>
     */
    protected array $visitorData = [];

    /**
     * Gym obstacles routines can use, listed in the obstacle library after the core's, in this order.
     *
     * @var list<class-string<Obstacle>>
     */
    protected array $obstacles = [];

    /**
     * @var list<class-string<AttemptListener>>
     */
    protected array $attemptListeners = [];

    /**
     * @var list<class-string<GymCheckHandler>>
     */
    protected array $gymCheckHandlers = [];

    /**
     * @var list<class-string<FrontGate>>
     */
    protected array $frontGates = [];

    /**
     * @var list<class-string<GymTriggerSource>>
     */
    protected array $gymTriggerSources = [];

    /**
     * Policies for the add-on's models, by model.
     *
     * @var array<class-string<Model>, class-string>
     */
    protected array $policies = [];

    /**
     * @var list<class-string<CaptureFeedContributor>>
     */
    protected array $captureFeed = [];

    /**
     * @var list<class-string<LockdownFeedContributor>>
     */
    protected array $lockdownFeed = [];

    /**
     * @var list<class-string<TarpitFeedContributor>>
     */
    protected array $tarpitFeed = [];

    /**
     * @var list<class-string<RewriteFeedContributor>>
     */
    protected array $rewriteFeed = [];

    /**
     * @var list<class-string<HoneypotFeedContributor>>
     */
    protected array $honeypotFeed = [];

    /**
     * IP addresses and CIDR ranges the add-on keeps past every enforcement (gym, tarpit, block,
     * blackhole), gathered with the core's by AllowedAddresses.
     *
     * @var list<class-string<AllowedAddressSource>>
     */
    protected array $allowedAddresses = [];

    /**
     * Models with a domain_id whose rows move to site when a www.site domain folds into it.
     *
     * @var list<class-string<Model>>
     */
    protected array $domainRecords = [];

    /**
     * @var list<class-string<LivewireComponentSource>>
     */
    protected array $livewireComponentSources = [];

    /**
     * @var list<class-string<HostnameClaimer>>
     */
    protected array $hostnameClaimers = [];

    /**
     * @var list<class-string<CertificateHostnameSource>>
     */
    protected array $certificateHostnames = [];

    /**
     * Enums of the request fields rule scripts and Activity searches can test once the add-on is
     * installed. A field named like one already listed (the core's) is left out.
     *
     * @var list<class-string<RuleFieldDefinition>>
     */
    protected array $ruleFields = [];

    /**
     * @var list<class-string<RuleTemplateSource>>
     */
    protected array $ruleTemplates = [];

    /**
     * @var list<class-string<RoutineTemplateSource>>
     */
    protected array $routineTemplates = [];

    /**
     * @var list<class-string<EdgeLineAddon>>
     */
    protected array $edgeLineAddons = [];

    /**
     * @var list<class-string<RequestAddon>>
     */
    protected array $requestAddons = [];

    /**
     * @var list<class-string<ActionAddon>>
     */
    protected array $actionAddons = [];

    /**
     * @var list<class-string<RuleDestinationSource>>
     */
    protected array $ruleDestinations = [];

    /**
     * Destination clauses the add-on's rules can name in their script (each a RuleDestinationHandler).
     *
     * @var list<class-string<RuleDestinationHandler>>
     */
    protected array $ruleDestinationHandlers = [];

    /**
     * @var list<class-string<RuleFilter>>
     */
    protected array $ruleFilters = [];

    /**
     * Tools the add-on gives AI agents on the MCP server (each a Laravel\Mcp\Server\Tool).
     *
     * @var list<class-string<Tool>>
     */
    protected array $mcpTools = [];

    /**
     * Views to draw in the core's view slots, by slot: a view name or a list of them, keyed by a
     * ViewSlot's value, as in [ViewSlot::BodyEnd->value => '{slug}::scripts'].
     *
     * @var array<string, view-string|list<view-string>>
     */
    protected array $slots = [];

    private ?Addon $addon = null;

    final public function register(): void
    {
        $addons = $this->app->make(Addons::class);
        $addons->add($this->addon());

        if (is_file($config = $this->packagePath('config/'.$this->addon()->slug.'.php'))) {
            $this->mergeConfigFrom($config, $this->addon()->slug);
        }

        $this->app->tag($this->gymAddons, GymAddons::TAG);
        $this->app->tag($this->clientAddons, ClientAddons::TAG);
        $this->app->tag($this->visitorData, VisitorDataHolders::TAG);
        $this->app->tag($this->obstacles, ObstacleRegistry::TAG);
        $this->app->tag($this->attemptListeners, AttemptListeners::TAG);
        $this->app->tag($this->gymCheckHandlers, GymCheckHandlers::TAG);
        $this->app->tag($this->frontGates, FrontGates::TAG);
        $this->app->tag($this->gymTriggerSources, GymTriggerSources::TAG);
        $this->app->tag($this->captureFeed, CaptureFeed::TAG);
        $this->app->tag($this->lockdownFeed, LockdownFeed::TAG);
        $this->app->tag($this->tarpitFeed, TarpitFeed::TAG);
        $this->app->tag($this->rewriteFeed, RewriteFeed::TAG);
        $this->app->tag($this->honeypotFeed, HoneypotFeed::TAG);
        $this->app->tag($this->allowedAddresses, AllowedAddresses::TAG);
        $this->app->tag($this->domainRecords, DomainRecords::TAG);
        $this->app->tag($this->livewireComponentSources, LivewireComponentSources::TAG);
        $this->app->tag($this->hostnameClaimers, HostnameClaimers::TAG);
        $this->app->tag($this->certificateHostnames, CertificateHostnames::TAG);
        $this->app->tag($this->ruleTemplates, RuleTemplates::TAG);
        $this->app->tag($this->routineTemplates, RoutineTemplates::TAG);
        $this->app->tag($this->edgeLineAddons, EdgeLineAddons::TAG);
        $this->app->tag($this->requestAddons, RequestAddons::TAG);
        $this->app->tag($this->actionAddons, ActionAddons::TAG);
        $this->app->tag($this->ruleDestinations, RuleDestinations::TAG);
        $this->app->tag($this->ruleDestinationHandlers, RuleDestinationHandlers::TAG);
        $this->app->tag($this->ruleFilters, RuleFilters::TAG);
        $this->app->tag($this->mcpTools, McpTools::TAG);
        $this->app->make(RuleFields::class)->add(...$this->ruleFields);

        $addons->claim($this->addon()->slug, [
            ...$this->gymAddons, ...$this->clientAddons, ...$this->visitorData, ...$this->obstacles, ...$this->attemptListeners,
            ...$this->gymCheckHandlers, ...$this->frontGates, ...$this->gymTriggerSources,
            ...$this->captureFeed, ...$this->lockdownFeed, ...$this->tarpitFeed, ...$this->rewriteFeed, ...$this->honeypotFeed, ...$this->allowedAddresses, ...$this->domainRecords, ...$this->livewireComponentSources,
            ...$this->hostnameClaimers, ...$this->certificateHostnames,
            ...$this->ruleTemplates, ...$this->routineTemplates, ...$this->edgeLineAddons, ...$this->requestAddons,
            ...$this->actionAddons, ...$this->ruleDestinations, ...$this->ruleDestinationHandlers, ...$this->ruleFilters, ...$this->mcpTools,
        ]);

        $this->registerAddon();
    }

    final public function boot(): void
    {
        $slug = $this->addon()->slug;

        if (is_dir($migrations = $this->packagePath('database/migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (is_dir($views = $this->packagePath('resources/views'))) {
            $this->loadViewsFrom($views, $slug);
        }

        if (is_dir($pages = $this->packagePath('resources/views/livewire'))) {
            Livewire::addNamespace($slug, viewPath: $pages);
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->bootRoutes();

        $this->app->make(Nav::class)->addFromAddon($slug, ...$this->nav());
        $this->app->make(SettingsNav::class)->addFromAddon($slug, ...$this->settingsNav());
        $this->app->make(AdminNav::class)->addFromAddon($slug, ...$this->adminNav());
        $this->app->make(WorkspaceMenu::class)->addFromAddon($slug, ...$this->workspaceMenu());

        foreach ($this->slots as $slot => $views) {
            $this->app->make(ViewSlots::class)->addFromAddon($slug, ViewSlot::from($slot), ...(array) $views);
        }

        $this->bootAddon();
    }

    /**
     * The add-on, read from its composer.json.
     */
    public function addon(): Addon
    {
        return $this->addon ??= Addon::fromPackage($this->packageRoot());
    }

    /**
     * Register anything the conventions don't cover.
     */
    protected function registerAddon(): void
    {
        //
    }

    /**
     * Boot anything the conventions don't cover.
     */
    protected function bootAddon(): void
    {
        //
    }

    /**
     * The sidebar links the add-on brings.
     *
     * @return list<NavItem>
     */
    protected function nav(): array
    {
        return [];
    }

    /**
     * The settings menu links the add-on brings.
     *
     * @return list<SettingsNavItem>
     */
    protected function settingsNav(): array
    {
        return [];
    }

    /**
     * The Admin section's menu links the add-on brings.
     *
     * @return list<SettingsNavItem>
     */
    protected function adminNav(): array
    {
        return [];
    }

    /**
     * The cog menu links the add-on brings, at the top right.
     *
     * @return list<WorkspaceMenuItem>
     */
    protected function workspaceMenu(): array
    {
        return [];
    }

    protected function packagePath(string $path = ''): string
    {
        return rtrim($this->packageRoot().'/'.$path, '/');
    }

    /**
     * Tag the add-on's classes into a registry, as $this->app->tag() does, and claim them as the
     * add-on's, so a registry that asks the switchboard leaves them out while the add-on is off.
     *
     * @param  list<class-string>  $classes
     */
    protected function tag(array $classes, string $tag): void
    {
        $this->app->tag($classes, $tag);
        $this->app->make(Addons::class)->claim($this->addon()->slug, $classes);
    }

    private function bootRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // Every route the add-on brings answers 404 while it's off, Livewire's updates to its pages included.
        $isOn = EnsureAddonIsOn::class.':'.$this->addon()->slug;

        if (isset($this->routes['team'])) {
            Route::middleware(['web', 'auth', 'verified', EnsureTeamMembership::class, $isOn])
                ->prefix('{current_team}')
                ->group($this->packagePath($this->routes['team']));
        }

        if (isset($this->routes['gym'])) {
            Route::prefix(config('gym.path'))
                ->middleware([SubstituteBindings::class, $isOn])
                ->group($this->packagePath($this->routes['gym']));
        }

        if (isset($this->routes['web'])) {
            Route::middleware($isOn)->group($this->packagePath($this->routes['web']));
        }
    }

    /**
     * The nearest folder above the provider that has a composer.json.
     */
    private function packageRoot(): string
    {
        $directory = dirname((string) (new ReflectionClass(static::class))->getFileName());

        while (! is_file($directory.'/composer.json')) {
            if ($directory === dirname($directory)) {
                throw new LogicException(static::class.' isn’t inside a Composer package.');
            }

            $directory = dirname($directory);
        }

        return $directory;
    }
}
