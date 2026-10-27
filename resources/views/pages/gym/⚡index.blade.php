<?php

use App\Actions\Gym\MatchGymRoutines;
use App\Actions\Gym\StartGymPreview;
use App\Data\GymVisitor;
use App\Enums\GymSessionStatus;
use App\Enums\HostingProvider;
use App\Gym\GymPipeline;
use App\Gym\GymPlan;
use App\Gym\HashPuzzle;
use App\Gym\ObstacleRegistry;
use App\Gym\RoutineTemplates;
use App\Models\EdgeRequest;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bot gym')] class extends Component {
    /**
     * How many days the gym's numbers cover.
     */
    public const int DAYS = 7;

    public string $testIp = '';

    public string $testUserAgent = '';

    public string $testCountry = '';

    public ?int $testAsn = null;

    public string $testPath = '/';

    public string $testHost = '';

    public string $testJa4 = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [GymRoutine::class, $this->team]);
    }

    /**
     * @return Collection<int, GymRoutine>
     */
    #[Computed]
    public function routines(): Collection
    {
        $since = now()->subDays($this::DAYS);

        return $this->team->gymRoutines()
            ->withCount([
                'sessions as entered_count' => fn ($query) => $query->where('is_preview', false)->where('started_at', '>=', $since),
                'sessions as cleared_count' => fn ($query) => $query->where('is_preview', false)->where('started_at', '>=', $since)->where('status', GymSessionStatus::Cleared),
            ])
            ->get();
    }

    /**
     * @return array{entered: int, cleared: int, turned_away: int, gave_up: int, hashes: int}
     */
    #[Computed]
    public function stats(): array
    {
        $row = $this->team->gymSessions()
            ->real()
            ->where('started_at', '>=', now()->subDays($this::DAYS))
            ->toBase()
            ->selectRaw('count(*) as entered')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cleared', [GymSessionStatus::Cleared->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as turned_away', [GymSessionStatus::TurnedAway->value])
            ->selectRaw('sum(case when status = ? and last_seen_at < ? then 1 else 0 end) as gave_up', [GymSessionStatus::Training->value, now()->subMinutes((int) config('gym.idle_minutes'))])
            ->selectRaw('coalesce(sum(hashes), 0) as hashes')
            ->first();

        return [
            'entered' => (int) $row->entered,
            'cleared' => (int) $row->cleared,
            'turned_away' => (int) $row->turned_away,
            'gave_up' => (int) $row->gave_up,
            'hashes' => (int) $row->hashes,
        ];
    }

    /**
     * @return Collection<int, GymSession>
     */
    #[Computed]
    public function recentSessions(): Collection
    {
        return $this->team->gymSessions()->real()->with('routines:id,name')->latest('started_at')->limit(12)->get();
    }

    /**
     * Recent visitors to fill the tester from.
     *
     * @return Collection<int, EdgeRequest>
     */
    #[Computed]
    public function recentRequests(): Collection
    {
        return $this->team->edgeRequests()
            ->with(['domain:id,hostname', 'requestPath', 'userAgentRecord', 'ja4Fingerprint', 'location'])
            ->latest('occurred_at')
            ->latest('id')
            ->limit(15)
            ->get();
    }

    /**
     * Which routines the visitor in the tester would enter, and the pipeline they'd stack into.
     *
     * @return array{routines: Collection<int, GymRoutine>, plan: GymPlan}|null
     */
    #[Computed]
    public function simulation(): ?array
    {
        if (filter_var($this->testIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $routines = app(MatchGymRoutines::class)->handle($this->team, $this->testVisitor());

        return [
            'routines' => $routines,
            'plan' => app(GymPipeline::class)->planFor($routines->map(fn (GymRoutine $routine) => $routine->blueprint())->all()),
        ];
    }

    public function sortRoutine(int $id, int $position): void
    {
        Gate::authorize('create', [GymRoutine::class, $this->team]);

        $ids = $this->team->gymRoutines()->pluck('id')->reject(fn (int $routineId) => $routineId === $id)->values()->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);

        foreach ($ids as $index => $routineId) {
            $this->team->gymRoutines()->whereKey($routineId)->update(['position' => $index + 1]);
        }

        unset($this->routines, $this->simulation);
    }

    public function toggle(int $id): void
    {
        $routine = $this->team->gymRoutines()->findOrFail($id);
        Gate::authorize('update', $routine);

        $routine->update(['enabled' => ! $routine->enabled]);

        unset($this->routines, $this->simulation);
    }

    public function fillFromRequest(int $requestId): void
    {
        $request = $this->team->edgeRequests()->findOrFail($requestId);

        $this->testIp = $request->client_ip;
        $this->testUserAgent = (string) $request->user_agent;
        $this->testCountry = (string) $request->country_code;
        $this->testAsn = $request->asn;
        $this->testPath = $request->path;
        $this->testHost = $request->domain->hostname;
        $this->testJa4 = (string) $request->ja4;
    }

    /**
     * Walk the pipeline the tester's visitor would get, as a preview.
     */
    public function walkSimulation(StartGymPreview $startGymPreview): ?string
    {
        $routines = $this->simulation['routines'] ?? null;

        if ($routines === null || $routines->isEmpty()) {
            return null;
        }

        return route('gym.show', $startGymPreview->handle($this->team, $routines->map(fn (GymRoutine $routine) => $routine->blueprint())->all()));
    }

    private function testVisitor(): GymVisitor
    {
        return new GymVisitor(
            ip: $this->testIp,
            host: $this->testHost ?: null,
            path: $this->testPath ?: '/',
            userAgent: $this->testUserAgent ?: null,
            ja4: $this->testJa4 ?: null,
            countryCode: $this->testCountry ? strtoupper($this->testCountry) : null,
            asn: $this->testAsn,
        );
    }
}; ?>

@php
    $registry = app(ObstacleRegistry::class);
    $stats = $this->stats;
@endphp

<div>
    <x-ntb.page-heading
        :overline="__('Keep the bots busy')"
        :title="__('Bot gym')"
        :description="__('Send the visitors you pick through obstacles that cost a bot CPU and time, and cost a person a moment. Every routine a visitor matches stacks into one pipeline.')"
    >
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" size="sm" icon="plus" :href="route('gym.routines.create')" wire:navigate data-test="new-routine">{{ __('New routine') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-ntb.page-heading>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="gym-stats">
        <x-ntb.metric :label="__('Visitors trained')" :value="Number::format($stats['entered'])" icon="fire" :hint="__('last :days days', ['days' => $this::DAYS])" />
        <x-ntb.metric :label="__('Cleared')" :value="$stats['entered'] ? Number::percentage($stats['cleared'] / $stats['entered'] * 100) : '—'" icon="check-circle" :hint="trans_choice(':count went on to their page|:count went on to their page', $stats['cleared'], ['count' => Number::format($stats['cleared'])])" />
        <x-ntb.metric :label="__('Gave up or turned away')" :value="Number::format($stats['gave_up'] + $stats['turned_away'])" icon="hand-raised" :hint="__(':gave gave up · :away turned away', ['gave' => Number::format($stats['gave_up']), 'away' => Number::format($stats['turned_away'])])" />
        <x-ntb.metric :label="__('Bot CPU burned')" :value="HashPuzzle::describeSeconds($stats['hashes'] / HashPuzzle::LAPTOP_HASHES_PER_SECOND)" icon="cpu-chip" :hint="__(':hashes hashes, at laptop speed', ['hashes' => Number::abbreviate($stats['hashes'], maxPrecision: 1)])" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem]">
        <div class="min-w-0 space-y-6">
            {{-- Routines --}}
            <x-ntb.panel :heading="__('Routines')" :subheading="__('Top to bottom is stacking order: a visitor matching several runs every routine’s obstacles, each once. Drag to reorder.')" :padded="false">
                @if ($this->routines->isEmpty())
                    <x-ntb.empty-state :heading="__('No routines yet.')">
                        {{ __('Start from a template, or build your own: pick who enters, stack some obstacles, choose the exit.') }}
                    </x-ntb.empty-state>
                @else
                    <ul wire:sort="sortRoutine" class="divide-y divide-line border-t border-line dark:divide-zinc-800 dark:border-zinc-800" data-test="routine-list">
                        @foreach ($this->routines as $routine)
                            @php
                                $routinePlan = app(GymPipeline::class)->planFor([$routine->blueprint()]);
                            @endphp
                            <li wire:key="routine-{{ $routine->id }}" wire:sort:item="{{ $routine->id }}" class="flex items-start gap-3 px-5 py-4" data-test="routine-row">
                                @if ($this->canManage)
                                    <span wire:sort:handle class="mt-1 cursor-grab text-zinc-300 hover:text-zinc-500 active:cursor-grabbing dark:text-zinc-600" title="{{ __('Drag to reorder') }}">
                                        <flux:icon.bars-2 variant="mini" class="size-4" />
                                    </span>
                                @endif

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('gym.routines.edit', $routine) }}" wire:navigate class="truncate font-semibold hover:text-coral-600 dark:hover:text-coral-400">{{ $routine->name }}</a>
                                        @unless ($routine->enabled)
                                            <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                                        @endunless
                                    </div>
                                    <p class="mt-0.5 line-clamp-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $routine->entry()->describe($this->team) }}</p>

                                    <div class="mt-2 flex flex-wrap items-center gap-1 text-xs">
                                        @forelse ($routinePlan->steps as $step)
                                            <span class="inline-flex items-center gap-1 rounded-md border border-line px-1.5 py-0.5 dark:border-zinc-700" title="{{ $step->obstacle->summarize($step->config) }}">
                                                <flux:icon :icon="$step->obstacle->icon()" variant="micro" class="size-3.5 text-coral-500" />{{ $step->obstacle->name() }}
                                                <span class="text-zinc-400 dark:text-zinc-500" data-test="obstacle-duration">{{ HashPuzzle::describeSeconds($step->obstacle->humanSeconds($step->config)) }}</span>
                                            </span>
                                            <flux:icon.chevron-right variant="micro" class="size-3 text-zinc-300 dark:text-zinc-600" />
                                        @empty
                                            <span class="text-zinc-400">{{ __('No obstacles') }}</span>
                                            <flux:icon.chevron-right variant="micro" class="size-3 text-zinc-300 dark:text-zinc-600" />
                                        @endforelse
                                        <flux:badge size="sm" :color="$routine->exit_action->color()" :icon="$routine->exit_action->icon()">{{ $routine->exit_action->label() }}</flux:badge>
                                    </div>
                                </div>

                                <div class="hidden w-28 shrink-0 text-end sm:block">
                                    <p class="font-mono text-sm tabular-nums">{{ Number::format($routine->entered_count) }}</p>
                                    <p class="text-[0.65rem] text-zinc-500 dark:text-zinc-400">
                                        {{ $routine->entered_count ? __(':percent cleared', ['percent' => Number::percentage($routine->cleared_count / $routine->entered_count * 100)]) : __('entered, :days days', ['days' => $this::DAYS]) }}
                                    </p>
                                </div>

                                @if ($this->canManage)
                                    <flux:switch :checked="$routine->enabled" wire:click="toggle({{ $routine->id }})" :aria-label="__('Enable :name', ['name' => $routine->name])" class="mt-1" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ntb.panel>

            {{-- Recent visits --}}
            <x-ntb.panel :heading="__('Recent visits')" :subheading="__('Visitors the edge sent through the gym. Previews aren’t counted.')" :padded="false">
                @if ($this->recentSessions->isEmpty())
                    <p class="border-t border-line px-5 py-8 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        {{ __('Nobody has trained yet. Visits show up here once the edge starts sending visitors to the gym.') }}
                    </p>
                @else
                    <div class="px-4 pb-2">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Visitor') }}</flux:table.column>
                                <flux:table.column class="max-md:hidden">{{ __('Routines') }}</flux:table.column>
                                <flux:table.column>{{ __('Progress') }}</flux:table.column>
                                <flux:table.column>{{ __('Status') }}</flux:table.column>
                                <flux:table.column align="end" class="max-sm:hidden">{{ __('Took') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->recentSessions as $session)
                                    <flux:table.row :key="'session-'.$session->id" data-test="session-row">
                                        <flux:table.cell class="font-mono text-xs">
                                            <x-ntb.label kind="ip" :value="$session->client_ip" />
                                            <x-ntb.country :code="$session->country_code" class="ms-1 text-zinc-400" />
                                        </flux:table.cell>
                                        <flux:table.cell class="max-w-48 truncate text-xs max-md:hidden">{{ $session->routines->pluck('name')->join(', ') }}</flux:table.cell>
                                        <flux:table.cell>
                                            <span class="flex items-center gap-1" title="{{ trans_choice(':count obstacle cleared|:count obstacles cleared', $session->clearedCount()) }}">
                                                @foreach ($session->progress as $stepKey => $stepState)
                                                    <span wire:key="dot-{{ $session->id }}-{{ $stepKey }}" @class([
                                                        'size-2 rounded-full',
                                                        'bg-emerald-500' => ($stepState['cleared_at'] ?? null) !== null && ($stepState['flags'] ?? []) === [],
                                                        'bg-amber-500' => ($stepState['flags'] ?? []) !== [],
                                                        'border border-zinc-400' => ($stepState['cleared_at'] ?? null) === null,
                                                    ])></span>
                                                @endforeach
                                            </span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if ($session->hasGivenUp())
                                                <flux:badge size="sm">{{ __('Gave up') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
                                            @endif
                                            @if ($session->switchedClients())
                                                <flux:badge size="sm" color="rose" icon="arrows-right-left" class="ms-1" title="{{ __('Attempts came from a different IP than the visitor who entered.') }}" data-test="switched-clients">{{ __('Handed off') }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell align="end" class="font-mono text-xs whitespace-nowrap tabular-nums max-sm:hidden">{{ HashPuzzle::describeSeconds($session->secondsInGym()) }}</flux:table.cell>
                                        <flux:table.cell align="end" class="text-xs whitespace-nowrap" title="{{ $session->started_at->toDayDateTimeString() }}" data-test="session-started">{{ $session->started_at->diffForHumans(short: true) }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif
            </x-ntb.panel>
        </div>

        <aside class="space-y-6">
            {{-- Templates --}}
            @if ($this->canManage)
                <x-ntb.panel :heading="__('Start from a template')">
                    <div class="grid gap-2">
                        @foreach (RoutineTemplates::all() as $key => $template)
                            <a href="{{ route('gym.routines.create', ['template' => $key]) }}" wire:navigate wire:key="template-{{ $key }}" class="flex gap-3 rounded-lg border border-line px-3 py-2.5 transition hover:border-coral-400 dark:border-zinc-800 dark:hover:border-coral-500" data-test="routine-template">
                                <flux:icon :icon="$template['icon']" variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-500" />
                                <span>
                                    <span class="block text-sm font-medium">{{ $template['name'] }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $template['blurb'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </x-ntb.panel>
            @endif

            {{-- Tester --}}
            <x-ntb.panel :heading="__('Test a visitor')" :subheading="__('See which routines a visitor would enter and the pipeline they’d stack into.')">
                <div class="space-y-3" data-test="gym-tester">
                    @if ($this->recentRequests->isNotEmpty())
                        <select x-on:change="$event.target.value && $wire.fillFromRequest($event.target.value); $event.target.value = ''" class="w-full rounded-md border border-line bg-white py-1.5 ps-2 pe-7 text-xs dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Fill from a recent request') }}">
                            <option value="">{{ __('Fill from a recent request…') }}</option>
                            @foreach ($this->recentRequests as $recent)
                                <option value="{{ $recent->id }}">{{ $recent->client_ip }} · {{ $recent->method->label() }} {{ \Illuminate\Support\Str::limit($recent->path, 32) }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div class="grid grid-cols-2 gap-2">
                        <flux:input wire:model.live.debounce.400ms="testIp" size="sm" :placeholder="__('IP, e.g. 203.0.113.7')" :aria-label="__('IP address')" class="col-span-2" />
                        <flux:input wire:model.live.debounce.400ms="testPath" size="sm" :placeholder="__('Path')" :aria-label="__('Path')" />
                        <flux:input wire:model.live.debounce.400ms="testCountry" size="sm" maxlength="2" :placeholder="__('Country, e.g. US')" :aria-label="__('Country')" />
                        <select wire:model.live="testAsn" class="col-span-2 rounded-lg border border-zinc-200 bg-white py-1.5 ps-2 pe-7 text-sm dark:border-white/10 dark:bg-white/10" aria-label="{{ __('Network') }}">
                            <option value="">{{ __('Home or office network') }}</option>
                            @if ($testAsn && ! HostingProvider::forAsn($testAsn))
                                <option value="{{ $testAsn }}">{{ __('AS:asn (not hosting)', ['asn' => $testAsn]) }}</option>
                            @endif
                            @foreach (HostingProvider::cases() as $provider)
                                <option value="{{ $provider->asns()[0] }}" @selected($testAsn !== null && in_array($testAsn, $provider->asns(), true))>{{ $provider->label() }}</option>
                            @endforeach
                        </select>
                        <flux:input wire:model.live.debounce.400ms="testUserAgent" size="sm" :placeholder="__('User agent')" :aria-label="__('User agent')" class="col-span-2" />
                    </div>

                    @if ($simulation = $this->simulation)
                        <div class="rounded-lg bg-canvas p-3 text-sm dark:bg-zinc-950/50" wire:loading.class="opacity-60" data-test="gym-simulation">
                            @if ($simulation['routines']->isEmpty())
                                <p class="text-zinc-600 dark:text-zinc-300">{{ __('No routine wants this visitor. They go straight to your origin.') }}</p>
                            @else
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice('Enters :count routine:|Enters :count routines:', $simulation['routines']->count()) }} <span class="font-medium text-ink dark:text-zinc-100">{{ $simulation['routines']->pluck('name')->join(', ') }}</span></p>
                                <div class="mt-2 flex flex-wrap items-center gap-1 text-xs">
                                    @foreach ($simulation['plan']->steps as $step)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-line bg-white px-1.5 py-0.5 dark:border-zinc-700 dark:bg-zinc-900">
                                            <flux:icon :icon="$step->obstacle->icon()" variant="micro" class="size-3.5 text-coral-500" />{{ $step->obstacle->name() }}
                                            <span class="text-zinc-400 dark:text-zinc-500">{{ HashPuzzle::describeSeconds($step->obstacle->humanSeconds($step->config)) }}</span>
                                        </span>
                                        <flux:icon.chevron-right variant="micro" class="size-3 text-zinc-300" />
                                    @endforeach
                                    <flux:badge size="sm" :color="$simulation['plan']->exit->color()">{{ $simulation['plan']->exit->label() }}</flux:badge>
                                </div>
                                <flux:button
                                    size="xs"
                                    icon="play"
                                    class="mt-3"
                                    x-data
                                    x-on:click="const tab = window.open('', '_blank'); $wire.walkSimulation().then(url => { url && tab ? tab.location = url : tab && tab.close() })"
                                >{{ __('Walk this pipeline') }}</flux:button>
                            @endif
                        </div>
                    @elseif ($testIp !== '')
                        <p class="text-xs text-rose-700 dark:text-rose-300">{{ __('That isn’t an IP address.') }}</p>
                    @endif
                </div>
            </x-ntb.panel>

            {{-- Obstacles --}}
            <x-ntb.panel :heading="__('Obstacle library')" :subheading="__('Each obstacle is a plugin. Write a class that implements App\\Gym\\Contracts\\Obstacle and list it in config/gym.php, or install an add-on that brings some.')">
                <ul class="space-y-3">
                    @foreach ($registry->all() as $obstacle)
                        <li class="flex gap-3" wire:key="library-{{ $obstacle->key() }}">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-coral-50 text-coral-600 dark:bg-coral-500/10 dark:text-coral-300">
                                <flux:icon :icon="$obstacle->icon()" variant="mini" class="size-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium">{{ $obstacle->name() }}</span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $obstacle->tagline() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ntb.panel>

            {{-- Edge hand-off --}}
            <section class="rounded-xl border border-dashed border-line p-5 text-sm dark:border-zinc-800">
                <p class="flex items-center gap-2 font-semibold"><flux:icon.arrows-right-left variant="mini" class="size-4 text-zinc-400" />{{ __('How visitors get here') }}</p>
                <ol class="mt-3 space-y-2 text-xs text-zinc-500 dark:text-zinc-400">
                    <li><span class="font-mono text-coral-600 dark:text-coral-400">1</span> {{ __('The edge matches a request against your enabled routines.') }}</li>
                    <li><span class="font-mono text-coral-600 dark:text-coral-400">2</span> {{ __('A match without a valid pass goes to the gym, on your own domain under /:path.', ['path' => config('gym.path')]) }}</li>
                    <li><span class="font-mono text-coral-600 dark:text-coral-400">3</span> {{ __('Cleared visitors carry a signed pass cookie back, and the edge lets them through until it expires.') }}</li>
                </ol>
                <p class="mt-3 text-xs text-zinc-400">{{ __('The edge hand-off isn’t switched on yet. Until it is, try routines with “Try it” and test visitors here.') }}</p>
            </section>
        </aside>
    </div>
</div>
