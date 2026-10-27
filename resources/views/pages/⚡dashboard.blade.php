<?php

use App\Actions\Analytics\SummarizeNotFound;
use App\Actions\Analytics\SummarizeTraffic;
use App\Data\TrafficSummary;
use App\Enums\DomainStatus;
use App\Enums\NotFoundCategory;
use App\Enums\RequestAction;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    #[Url]
    public string $period = '24h';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function trafficPeriod(): TrafficPeriod
    {
        return TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;
    }

    /**
     * The period's totals and chart, kept a minute or more (and served stale while they're counted
     * again): a busy team's day is close to a million requests.
     */
    #[Computed]
    public function summary(): TrafficSummary
    {
        $summary = $this->remember('summary', fn (): array => get_object_vars(app(SummarizeTraffic::class)->handle($this->team, $this->trafficPeriod)));

        return new TrafficSummary(...[...$summary, 'period' => $this->trafficPeriod]);
    }

    /**
     * @return array<int, array{path: string, category: NotFoundCategory, hits: int, uniqueIps: int, domains: int, lastSeenAt: string}>
     */
    #[Computed]
    public function notFoundPaths(): array
    {
        $paths = $this->remember('not-found-paths', fn (): array => array_map(
            fn (array $path): array => [...$path, 'category' => $path['category']->value],
            app(SummarizeNotFound::class)->topPaths($this->team, $this->trafficPeriod, limit: 5),
        ));

        return array_map(fn (array $path): array => [...$path, 'category' => NotFoundCategory::from($path['category'])], $paths);
    }

    /**
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        $since = $this->trafficPeriod->start();

        return Domain::hydrate($this->remember('domains', fn (): array => $this->team->domains()
            ->withCount([
                'edgeRequests as requests_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $since),
                'edgeRequests as suspicious_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $since)->where('action', '<>', RequestAction::Passed),
            ])
            ->orderByDesc('requests_count')
            ->orderBy('hostname')
            ->limit(8)
            ->get()
            ->map(fn (Domain $domain): array => $domain->getAttributes())
            ->all()));
    }

    /**
     * Count every domain, and the live ones, without loading them: an origin can bring thousands.
     *
     * @return array{total: int, active: int}
     */
    #[Computed]
    public function domainCounts(): array
    {
        return [
            'total' => $this->team->domains()->count(),
            'active' => $this->team->domains()->where('status', DomainStatus::Active)->count(),
        ];
    }

    /**
     * @return Collection<int, EdgeRequest>
     */
    #[Computed]
    public function recentlyCaught(): Collection
    {
        return $this->team->edgeRequests()
            ->with('domain:id,hostname')
            ->whereIn('action', RequestAction::suspicious())
            ->latest('occurred_at')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    /**
     * Keep one of the dashboard's counts for the team and period a while (see TrafficPeriod::cacheSeconds),
     * then serve it stale while it's counted again in the background. Only plain arrays go in: the
     * cache unserializes no classes.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    protected function remember(string $key, Closure $callback): mixed
    {
        return Cache::flexible("dashboard:{$key}:{$this->team->id}:{$this->trafficPeriod->value}", $this->trafficPeriod->cacheSeconds(), $callback);
    }

    #[Computed]
    public function greeting(): string
    {
        return match (true) {
            now()->hour < 12 => __('Good morning, human'),
            now()->hour < 18 => __('Good afternoon, human'),
            default => __('Good evening, human'),
        };
    }
}; ?>

<div>
    <livewire:pages::teams.pending-invitations-modal />

    <x-ntb.page-heading
        :overline="__('Your daily dose of bot karma')"
        :title="$this->greeting"
        :description="__('Here\'s what\'s been knocking on your doors.')"
    >
        @if ($this->domains->isNotEmpty())
            <x-slot:actions>
                <flux:select wire:model.live="period" size="sm" class="w-40" :aria-label="__('Time period')">
                    @foreach (TrafficPeriod::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @can('create', [Domain::class, $this->team])
                    <flux:modal.trigger name="add-domain">
                        <flux:button variant="primary" size="sm" icon="plus">{{ __('Add domain') }}</flux:button>
                    </flux:modal.trigger>
                @endcan
            </x-slot:actions>
        @endif
    </x-ntb.page-heading>

    @if ($this->domains->isEmpty())
        <x-ntb.panel>
            <x-ntb.empty-state :heading="__('Nothing is knocking yet.')">
                {{ __('Add the first domain you want to protect. NiceTryBot sits in front of your origin, tags scanners, and gives you analytics for every request, including every 404.') }}

                @can('create', [Domain::class, $this->team])
                    <x-slot:actions>
                        <flux:modal.trigger name="add-domain">
                            <flux:button variant="primary" icon="plus">{{ __('Add your first domain') }}</flux:button>
                        </flux:modal.trigger>
                    </x-slot:actions>
                @endcan
            </x-ntb.empty-state>

            <div class="grid gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-3 dark:border-zinc-800 dark:bg-zinc-800">
                @foreach ([
                    ['step' => '01', 'title' => __('Add a domain origin'), 'text' => __('Your hostname and the origin URL your app already runs on.')],
                    ['step' => '02', 'title' => __('Verify and point DNS'), 'text' => __('One TXT record proves it\'s yours. A CNAME sends traffic through the edge.')],
                    ['step' => '03', 'title' => __('Watch, then enforce'), 'text' => __('Start in observe mode. Send scanners to the gym when you trust the tags.')],
                ] as $onboardingStep)
                    <div class="bg-white p-5 dark:bg-zinc-900" wire:key="onboarding-{{ $onboardingStep['step'] }}">
                        <p class="font-mono text-xs text-coral-600 dark:text-coral-400">{{ $onboardingStep['step'] }}</p>
                        <p class="mt-2 text-sm font-semibold">{{ $onboardingStep['title'] }}</p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $onboardingStep['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-ntb.panel>
    @else
        @php($summary = $this->summary)
        @php($activeDomains = $this->domainCounts['active'])

        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="inline-flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                {{ __('Protection running') }}
            </span>
            <span class="text-zinc-300 dark:text-zinc-700">/</span>
            <span>{{ trans_choice(':count domain protected|:count domains protected', $activeDomains) }}</span>
            @if ($this->domainCounts['total'] > $activeDomains)
                <span class="text-zinc-300 dark:text-zinc-700">/</span>
                <a href="{{ route('domains.index') }}" class="text-amber-700 hover:underline dark:text-amber-400" wire:navigate>
                    {{ trans_choice(':count needs attention|:count need attention', $this->domainCounts['total'] - $activeDomains) }}
                </a>
            @endif
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="period">
            <x-ntb.metric
                :label="__('Requests')"
                :value="Number::format($summary->requests)"
                icon="signal"
                :change="$summary->change($summary->requests, $summary->previousRequests)"
                :change-label="$this->trafficPeriod->comparisonLabel()"
                :hint="__(':count unique visitors', ['count' => Number::format($summary->uniqueIps)])"
            />
            <x-ntb.metric
                :label="__('Tagged suspicious')"
                :value="Number::format($summary->suspicious)"
                icon="shield-check"
                :change="$summary->change($summary->suspicious, $summary->previousSuspicious)"
                :change-label="$this->trafficPeriod->comparisonLabel()"
                :rising-is-good="false"
            />
            <x-ntb.metric
                :label="__('404s logged')"
                :value="Number::format($summary->notFound)"
                icon="document-magnifying-glass"
                :change="$summary->change($summary->notFound, $summary->previousNotFound)"
                :change-label="$this->trafficPeriod->comparisonLabel()"
                :rising-is-good="false"
            />
            <x-ntb.metric
                :label="__('Kept off your origin')"
                :value="Number::format($summary->keptOffOrigin)"
                icon="lock-closed"
                :hint="__(':share% of traffic was suspicious', ['share' => $summary->suspiciousShare()])"
                :href="route('activity.index', ['action' => 'kept-off', 'period' => $this->period])"
            />
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-3">
            <x-ntb.panel class="lg:col-span-2" :heading="__('Traffic at a glance')" :subheading="__('A little perspective on all that knocking.')">
                <x-ntb.traffic-chart :buckets="$summary->buckets" wire:loading.class="opacity-60" wire:target="period" />

                @if ($busiest = $summary->busiestSuspiciousBucket())
                    <div class="mt-5 flex flex-wrap items-center gap-3 rounded-lg bg-canvas px-4 py-3 text-sm dark:bg-zinc-800/60">
                        <flux:icon.shield-check variant="mini" class="size-4 text-coral-600 dark:text-coral-400" />
                        <span class="flex-1 text-zinc-600 dark:text-zinc-300">
                            {{ __('The busiest burst was :label, with :count suspicious requests.', ['label' => $busiest['label'], 'count' => Number::format($busiest['suspicious'])]) }}
                        </span>
                        <flux:link :href="route('activity.index', ['action' => 'suspicious', 'period' => $this->period])" class="text-sm" wire:navigate>{{ __('See activity') }} →</flux:link>
                    </div>
                @endif
            </x-ntb.panel>

            <x-ntb.panel :heading="__('Your domains')" :subheading="__('Busiest in the selected period.')" :padded="false">
                <x-slot:actions>
                    <flux:link :href="route('domains.index')" class="text-xs" wire:navigate>{{ __('Manage') }} →</flux:link>
                </x-slot:actions>

                <ul class="divide-y divide-line dark:divide-zinc-800">
                    @foreach ($this->domains as $domain)
                        <li wire:key="overview-domain-{{ $domain->id }}">
                            <a href="{{ route('domains.show', $domain) }}" class="flex items-center gap-3 px-5 py-3 transition hover:bg-canvas dark:hover:bg-zinc-800/60" wire:navigate>
                                <span @class([
                                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                    'bg-coral-50 text-coral-700 dark:bg-coral-500/10 dark:text-coral-300' => $domain->status === DomainStatus::Active,
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $domain->status === DomainStatus::PendingVerification,
                                    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $domain->status === DomainStatus::Paused,
                                ])>
                                    <flux:icon.globe-alt variant="mini" class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ $domain->hostname }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $domain->status->label() }} · {{ $domain->protection_mode->label() }}</span>
                                </span>
                                <span class="text-end">
                                    <span class="block font-mono text-sm tabular-nums">{{ Number::abbreviate($domain->requests_count) }}</span>
                                    <span class="block font-mono text-xs text-coral-700 tabular-nums dark:text-coral-300">{{ Number::abbreviate($domain->suspicious_count) }} {{ __('flagged') }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ntb.panel>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-ntb.panel class="lg:col-span-2" :heading="__('Recently caught in the act')" :subheading="__('Every flag has a story. Here\'s the evidence.')">
                <x-slot:actions>
                    <flux:link :href="route('activity.index', ['action' => 'suspicious'])" class="text-xs" wire:navigate>{{ __('View all') }} →</flux:link>
                </x-slot:actions>

                @if ($this->recentlyCaught->isEmpty())
                    <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No suspicious requests yet. Enjoy the quiet.') }}</p>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('IP address') }}</flux:table.column>
                            <flux:table.column>{{ __('What it tried') }}</flux:table.column>
                            <flux:table.column>{{ __('Action') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->recentlyCaught as $request)
                                <flux:table.row :key="$request->id">
                                    <flux:table.cell class="font-mono text-xs">
                                        <x-ntb.label kind="ip" :value="$request->client_ip" />
                                        <x-ntb.country :code="$request->country_code" class="ms-1 text-zinc-400" />
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-64">
                                        <span class="block truncate font-mono text-xs text-ink dark:text-zinc-100">{{ $request->method->label() }} {{ $request->path }}</span>
                                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $request->domain->hostname }} · {{ $request->bot_tag }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell><x-ntb.action-badge :action="$request->action" /></flux:table.cell>
                                    <flux:table.cell align="end" class="text-xs whitespace-nowrap">{{ $request->occurred_at->diffForHumans(short: true) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </x-ntb.panel>

            <div class="flex flex-col gap-6">
                <section class="relative overflow-hidden rounded-xl bg-ink p-6 text-white dark:bg-zinc-900 dark:ring-1 dark:ring-zinc-800">
                    <div class="flex items-center justify-between">
                        <span class="rounded bg-amber-300 px-2 py-0.5 font-mono text-[0.65rem] font-bold tracking-widest text-ink">{{ __('BOT GYM') }}</span>
                        <span class="font-mono text-[0.65rem] tracking-widest text-white/50">{{ __('NO PAIN, NO GAIN.') }}</span>
                    </div>
                    <h2 class="mt-5 text-xl leading-snug font-bold tracking-tight">{{ __('They\'re working hard.') }}<br>{{ __('You\'re hardly working.') }}</h2>
                    <p class="mt-5 text-4xl font-bold tracking-tight text-coral-400 tabular-nums">{{ $summary->gymTime() }}</p>
                    <p class="mt-1 text-xs text-white/60">{{ __('of scanner time spent in the gym instead of on your origin') }}</p>
                    <a href="{{ route('gym.index') }}" wire:navigate class="mt-5 inline-flex items-center gap-1 text-xs font-medium text-white/70 hover:text-white">{{ __('Build a workout') }} <flux:icon.arrow-right variant="micro" class="size-3" /></a>
                </section>

                <x-ntb.panel :heading="__('Top 404s')" :padded="false">
                    <x-slot:actions>
                        <flux:link :href="route('not-found.index', ['period' => $this->period])" class="text-xs" wire:navigate>{{ __('All 404s') }} →</flux:link>
                    </x-slot:actions>

                    <ul class="divide-y divide-line dark:divide-zinc-800">
                        @forelse ($this->notFoundPaths as $path)
                            <li class="flex items-center gap-3 px-5 py-2.5" wire:key="top-404-{{ md5($path['path']) }}">
                                <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ $path['path'] }}</span>
                                <flux:badge size="sm" :color="$path['category']->color()">{{ $path['category']->label() }}</flux:badge>
                                <span class="w-10 text-end font-mono text-xs tabular-nums">{{ Number::abbreviate($path['hits']) }}</span>
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No 404s in this period.') }}</li>
                        @endforelse
                    </ul>
                </x-ntb.panel>
            </div>
        </div>
    @endif
</div>
