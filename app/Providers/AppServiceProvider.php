<?php

namespace App\Providers;

use App\Data\CrawlerDirectory;
use App\Data\LabelDirectory;
use App\Gym\Contracts\Obstacle;
use App\Gym\ObstacleRegistry;
use App\Http\Middleware\AddServerTiming;
use App\Http\Middleware\EnsureAddonIsOn;
use App\Models\GymSession;
use App\Models\User;
use App\Support\CompressResponse;
use App\Support\CoreVisitorData;
use App\Support\Nav;
use App\Support\NavItem;
use App\Support\SettingsNav;
use App\Support\SettingsNavItem;
use App\Support\SharedOrigins;
use App\Support\ViewerIp;
use App\Support\VisitorDataHolders;
use App\Support\WorkspaceMenu;
use App\Support\WorkspaceMenuItem;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Queries a page runs that take at least this long are logged, to find what to speed up next.
     */
    private const int SLOW_QUERY_MILLISECONDS = 300;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(LabelDirectory::class);
        $this->app->scoped(CrawlerDirectory::class);

        $this->app->tag([CoreVisitorData::class], VisitorDataHolders::TAG);

        // The core's obstacles in their config order, then the ones add-ons bring.
        $this->app->singleton(ObstacleRegistry::class, fn ($app) => new ObstacleRegistry([
            ...array_map(fn (string $class): Obstacle => $app->make($class), config('gym.obstacles', [])),
            ...array_filter(iterator_to_array($app->tagged(ObstacleRegistry::TAG), false), fn (mixed $obstacle) => $obstacle instanceof Obstacle),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureNav();
        $this->configureSettingsNav();
        $this->configureWorkspaceMenu();
        $this->configureAdministration();

        // After Livewire's own listener, which adds its scripts to the page.
        Event::listen(RequestHandled::class, CompressResponse::class);

        DB::listen(function (QueryExecuted $query): void {
            AddServerTiming::recordQuery($query->time);

            if ($query->time >= self::SLOW_QUERY_MILLISECONDS && ! app()->runningInConsole()) {
                Log::warning('Slow query', ['ms' => round($query->time), 'url' => request()->fullUrl(), 'sql' => Str::limit($query->sql, 3000)]);
            }
        });
    }

    /**
     * The core's pages in the sidebar, spaced ten apart so add-ons can slot theirs in between.
     */
    protected function configureNav(): void
    {
        $this->app->make(Nav::class)->add(
            new NavItem('Overview', 'squares-2x2', 'dashboard', order: 10),
            new NavItem('Activity', 'chart-bar', 'activity.index', order: 30, current: ['activity.*'], ai: true),
            new NavItem('Analytics', 'presentation-chart-line', 'analytics.index', order: 40, current: ['analytics.*']),
            new NavItem('404s', 'document-magnifying-glass', 'not-found.index', order: 50, current: ['not-found.*']),
            new NavItem('Rules', 'scale', 'rules.index', order: 60, current: ['rules.*'], ai: true),
            // Flags the page while a rule holds the IP you're browsing from, so you can let yourself go.
            new NavItem('Tags & bans', 'tag', 'tags.index', order: 70, current: ['tags.*'], badge: function (): ?string {
                $viewerIp = ViewerIp::of(request());

                return $viewerIp !== null && (bool) auth()->user()?->currentTeam?->verdicts()->active()->where('client_ip', $viewerIp)->exists() ? __('You') : null;
            }, badgeColor: 'red', test: 'nav-tags'),
            new NavItem('Bot gym', 'fire', 'gym.index', order: 90, current: ['gym.index', 'gym.routines.*']),
        );
    }

    /**
     * The core's settings in the settings menu, spaced ten apart so add-ons can slot theirs in between.
     */
    protected function configureSettingsNav(): void
    {
        $this->app->make(SettingsNav::class)->add(
            new SettingsNavItem('Profile', 'profile.edit', order: 10),
            new SettingsNavItem('Security', 'security.edit', order: 20),
            new SettingsNavItem('Teams', 'teams.index', order: 30, current: ['teams.*']),
            new SettingsNavItem('Appearance', 'appearance.edit', order: 40),
            new SettingsNavItem('Artificial Intelligence', 'ai.edit', order: 50),
        );
    }

    /**
     * The core's links in the cog menu, spaced ten apart so add-ons can slot theirs in between.
     */
    protected function configureWorkspaceMenu(): void
    {
        $this->app->make(WorkspaceMenu::class)->add(
            // Domains of their own, plus shared origins: each origin's sites are counted under it.
            new WorkspaceMenuItem('Domains & origins', 'globe-alt', 'domains.index', order: 10, badge: function (): ?string {
                $team = auth()->user()?->currentTeam;
                $siteCount = $team === null ? 0 : $team->domains()->standalone()->count() + app(SharedOrigins::class)->origins($team)->count();

                return $siteCount > 0 ? number_format($siteCount) : null;
            }, test: 'nav-domains'),
            new WorkspaceMenuItem('Visitor data', 'shield-check', 'visitor-data.index', order: 20, test: 'nav-visitor-data'),
        );
    }

    /**
     * Limit how fast one gym session can submit attempts. A waiting room sends a round about once a
     * second on a fast machine; anything far beyond that is a script hammering the gym.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('gym', function (Request $request) {
            $session = $request->route('gymSession');

            return Limit::perMinute(300)->by($session instanceof GymSession ? 'gym:'.$session->id : 'gym-ip:'.$request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    /**
     * Who runs the installation, and the add-on switch's reach into Livewire: updates to an add-on's page
     * run its route's EnsureAddonIsOn again, so turning the add-on off stops a page left open.
     */
    protected function configureAdministration(): void
    {
        Gate::define('administer', fn (User $user): bool => $user->isAdministrator());

        Livewire::addPersistentMiddleware(EnsureAddonIsOn::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
