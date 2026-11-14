<?php

use App\Actions\Analytics\CountOverTime;
use App\Data\RequestQuery;
use App\Enums\ChartInterval;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Enums\TrafficPeriod;
use App\Gym\HashPuzzle;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;
use NiceTry\Tarpit\Rules\StatusHolds;

new #[Title('Tarpits')] class extends Component
{
    /**
     * How far back the activity chart reaches.
     */
    #[Url(except: '7d')]
    public string $period = '7d';

    /**
     * How much time each bar of that chart covers ("1h", "1d", …), or "" for the period's default.
     */
    #[Url(as: 'by', except: '')]
    public string $chartInterval = '';

    /**
     * What the chart counts over time: "holds" (requests the tarpits held) or "tarpits" (tarpits added).
     */
    #[Url(as: 'chart', except: 'holds')]
    public string $chartSource = 'holds';

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public bool $enabled = true;

    public bool $allDomains = true;

    /** @var list<int|string> */
    public array $domainIds = [];

    /**
     * The quiet needed to step back each try, in seconds and in turn (Tarpit Pro); blank for none.
     */
    public string $cooldowns = '';

    public bool $takesGymNoShows = false;

    public bool $countsNotFound = false;

    public int $notFoundCount = 10;

    public int $notFoundWithin = 60;

    public bool $countsBursts = false;

    public int $burstCount = 30;

    public int $burstWithin = 10;




    public int $freeTries = 1;

    public string $delays = '2, 8, 24';

    public int $maxDelay = 30;

    public int $maxHeld = 100;

    public int $whenFull = TarpitWhenFull::Reject->value;

    public int $afterWait = TarpitAfterWait::Continue->value;

    public int $forgetMinutes = 15;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [Tarpit::class, $this->team]);
    }

    /**
     * Whether Tarpit Pro is on, which unlocks holding any status, asset-safe 404s, the cooldown ladder
     * Off, the editor shows the free behaviour and an upsell.
     */
    #[Computed]
    public function pro(): bool
    {
        return Tarpit::proIsOn();
    }

    /**
     * @return Collection<int, Tarpit>
     */
    #[Computed]
    public function tarpits(): Collection
    {
        return $this->team->tarpits()
            ->with(['rules:edge_rules.id,edge_rules.name,edge_rules.enabled,edge_rules.source', 'domains:domains.id,domains.hostname'])
            ->orderBy('name')
            ->get();
    }

    /**
     * What each tarpit did in the last day, by tarpit id: requests it touched, how many it held, the
     * clients behind them, and the time it held them for.
     *
     * @return array<int, array{requests: int, held: int, clients: int, held_ms: int}>
     */
    #[Computed]
    public function lastDay(): array
    {
        $ids = $this->tarpits->modelKeys();

        if ($ids === []) {
            return [];
        }

        return TarpitHold::query()
            ->whereIn('tarpit_holds.tarpit_id', $ids)
            ->where('tarpit_holds.created_at', '>=', now()->subDay())
            ->join('edge_requests', 'edge_requests.id', '=', 'tarpit_holds.edge_request_id')
            ->groupBy('tarpit_holds.tarpit_id')
            ->toBase()
            ->get([
                'tarpit_holds.tarpit_id',
                DB::raw('count(*) as requests'),
                DB::raw('sum(case when tarpit_holds.outcome = '.TarpitOutcome::Held->value.' then 1 else 0 end) as held'),
                DB::raw('count(distinct edge_requests.client_ip) as clients'),
                DB::raw('coalesce(sum(tarpit_holds.held_ms), 0) as held_ms'),
            ])
            ->mapWithKeys(fn (object $row) => [(int) $row->tarpit_id => [
                'requests' => (int) $row->requests,
                'held' => (int) $row->held,
                'clients' => (int) $row->clients,
                'held_ms' => (int) $row->held_ms,
            ]])
            ->all();
    }

    /**
     * The period the activity chart covers, falling back to the last 7 days.
     */
    #[Computed]
    public function chartPeriod(): TrafficPeriod
    {
        return TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Week;
    }

    /**
     * A fresh period starts the bar size over on its default.
     */
    public function updatedPeriod(): void
    {
        $this->chartInterval = '';
    }

    /**
     * The chart's bars across the period: how many requests the team's tarpits held, or how many tarpits
     * the team added, counted by when each happened.
     *
     * @return array<int, array{key: string, label: string, start: int, end: int, count: int}>
     */
    #[Computed]
    public function chartBuckets(): array
    {
        $interval = ChartInterval::resolve($this->chartInterval, $this->chartPeriod);

        if ($this->chartSource === 'tarpits') {
            return app(CountOverTime::class)->handle(
                $this->team->tarpits()->toBase(),
                'tarpits.created_at',
                $this->chartPeriod,
                $interval,
            );
        }

        // How many requests were held each bar, and the time they were held for, so each bar can be
        // tinted by the bot-time it cost.
        $buckets = app(CountOverTime::class)->handle(
            TarpitHold::query()->whereIn('tarpit_id', $this->team->tarpits()->select('tarpits.id'))->toBase(),
            'tarpit_holds.created_at',
            $this->chartPeriod,
            $interval,
            sumColumn: 'tarpit_holds.held_ms',
        );

        return $this->tintByTime($buckets);
    }

    /**
     * Tint each bar by the time its tarpits held clients that bar: faint for a light wait, deep violet
     * for the bars that cost bots the most. Height still reads as how many requests were held, so a bar
     * can be tall but pale (many quick holds) or short but deep (a few long ones).
     *
     * @param  array<int, array{key: string, label: string, start: int, end: int, count: int, weight?: int}>  $buckets
     * @return array<int, array{key: string, label: string, start: int, end: int, count: int, weight?: int, fill?: string, note?: string}>
     */
    private function tintByTime(array $buckets): array
    {
        $max = max(1, (int) collect($buckets)->max('weight'));

        return array_map(function (array $bucket) use ($max) {
            $weight = (int) ($bucket['weight'] ?? 0);

            if ($weight > 0) {
                // A square-root curve so the many ordinary waits still separate from the quietest bars.
                $alpha = 35 + (int) round(sqrt($weight / $max) * 65);
                $bucket['fill'] = "color-mix(in oklab, var(--chart-spoofed) {$alpha}%, transparent)";
                $bucket['note'] = __('Held :time', ['time' => $this->describeHeld($weight)]);
            }

            return $bucket;
        }, $buckets);
    }

    /**
     * The statuses a rule has its tarpit hold the origin's answer on, when it reads nothing but the status.
     *
     * @return list<int>|null
     */
    public function statusesHeldBy(\App\Models\EdgeRule $rule): ?array
    {
        $script = rescue(fn () => $rule->script(), report: false);

        return $script === null ? null : StatusHolds::of($script);
    }

    /**
     * Determine if one request decides the rule, so its tarpit holds the very request it matches.
     */
    public function holdsTheRequest(\App\Models\EdgeRule $rule): bool
    {
        return rescue(fn () => $rule->script()->isLive(), false, report: false);
    }

    /**
     * The team's domains, to pick from, with whether each is in Enforce mode (only those hold anyone).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Domain>
     */
    #[Computed]
    public function domains(): \Illuminate\Support\Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname', 'protection_mode', 'status']);
    }

    #[Computed]
    public function enforcingCount(): int
    {
        return $this->domains->filter(fn ($domain) => $domain->protection_mode === ProtectionMode::Enforce && $domain->status === DomainStatus::Active)->count();
    }

    /**
     * Open the editor for a new tarpit, with the usual waits.
     */
    public function create(): void
    {
        Gate::authorize('create', [Tarpit::class, $this->team]);

        $this->resetValidation();
        $this->reset(['editingId', 'name', 'enabled', 'allDomains', 'domainIds', 'cooldowns', 'takesGymNoShows', 'countsNotFound', 'notFoundCount', 'notFoundWithin', 'countsBursts', 'burstCount', 'burstWithin', 'freeTries', 'delays', 'maxDelay', 'maxHeld', 'whenFull', 'afterWait', 'forgetMinutes']);
        $this->name = $this->tarpits->isEmpty() ? __('Probers') : '';

        Flux::modal('tarpit-editor')->show();
    }

    /**
     * Open the editor on one of the team's tarpits.
     */
    public function edit(int $tarpitId): void
    {
        $tarpit = $this->team->tarpits()->with('domains:domains.id')->findOrFail($tarpitId);
        Gate::authorize('update', $tarpit);

        $this->resetValidation();
        $this->editingId = $tarpit->id;
        $this->name = $tarpit->name;
        $this->enabled = $tarpit->enabled;
        $this->allDomains = $tarpit->all_domains;
        $this->domainIds = $tarpit->domains->modelKeys();
        $this->cooldowns = implode(', ', $tarpit->cooldowns ?? []);
        $this->takesGymNoShows = $tarpit->takes_gym_no_shows;
        $this->countsNotFound = $tarpit->not_found_count !== null;
        $this->notFoundCount = $tarpit->not_found_count ?? 10;
        $this->notFoundWithin = $tarpit->not_found_within_seconds;
        $this->countsBursts = $tarpit->burst_count !== null;
        $this->burstCount = $tarpit->burst_count ?? 30;
        $this->burstWithin = $tarpit->burst_within_seconds;
        $this->freeTries = $tarpit->free_tries;
        $this->delays = implode(', ', $tarpit->delays);
        $this->maxDelay = $tarpit->max_delay_seconds;
        $this->maxHeld = $tarpit->max_held;
        $this->whenFull = $tarpit->when_full->value;
        $this->afterWait = $tarpit->after_wait->value;
        $this->forgetMinutes = intdiv($tarpit->forget_after_seconds, 60);

        Flux::modal('tarpit-editor')->show();
    }

    /**
     * Save the tarpit being edited (or the new one). The edge has it within a few seconds.
     */
    public function save(): void
    {
        $tarpit = $this->editingId === null ? null : $this->team->tarpits()->findOrFail($this->editingId);
        $tarpit === null ? Gate::authorize('create', [Tarpit::class, $this->team]) : Gate::authorize('update', $tarpit);

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tarpits', 'name')->where('team_id', $this->team->id)->ignore($this->editingId)],
            'domainIds' => ['array', Rule::requiredIf(! $this->allDomains)],
            'domainIds.*' => ['integer', Rule::exists('domains', 'id')->where('team_id', $this->team->id)],
            'cooldowns' => ['nullable', 'string', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3}){0,9}\s*$/'],
            'notFoundCount' => ['integer', 'min:2', 'max:1000'],
            'notFoundWithin' => ['integer', 'min:5', 'max:3600'],
            'burstCount' => ['integer', 'min:5', 'max:10000'],
            'burstWithin' => ['integer', 'min:1', 'max:600'],
            'freeTries' => ['integer', 'min:0', 'max:20'],
            'delays' => ['required', 'string', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3}){0,9}\s*$/'],
            'maxDelay' => ['integer', 'min:1', 'max:'.Tarpit::LONGEST_DELAY],
            'maxHeld' => ['integer', 'min:1', 'max:'.Tarpit::MOST_HELD],
            'whenFull' => [Rule::enum(TarpitWhenFull::class)],
            'afterWait' => [Rule::enum(TarpitAfterWait::class)],
            'forgetMinutes' => ['integer', 'min:1', 'max:1440'],
        ], [
            'delays.regex' => __('List up to ten waits in seconds, like 2, 8, 24.'),
            'cooldowns.regex' => __('List up to ten cooldowns in seconds, like 10, 20, 30.'),
            'domainIds.required' => __('Pick at least one domain, or cover them all.'),
        ]);

        // Tarpit Pro unlocks the ladder; without it, keep what was set but save no new one.
        $pro = $this->pro;
        $delays = array_map(fn (string $seconds) => max(0, min((int) trim($seconds), $this->maxDelay)), explode(',', $this->delays));
        $cooldowns = $pro && trim($this->cooldowns) !== ''
            ? array_map(fn (string $seconds) => max(1, min((int) trim($seconds), Tarpit::LONGEST_COOLDOWN)), explode(',', $this->cooldowns))
            : ($pro ? null : $tarpit?->cooldowns);

        $attributes = [
            'name' => trim($this->name),
            'enabled' => $this->enabled,
            'all_domains' => $this->allDomains,
            'free_tries' => $this->freeTries,
            'delays' => $delays,
            'max_delay_seconds' => $this->maxDelay,
            'max_held' => $this->maxHeld,
            'when_full' => TarpitWhenFull::from($this->whenFull),
            'after_wait' => TarpitAfterWait::from($this->afterWait),
            'forget_after_seconds' => $this->forgetMinutes * 60,
            'cooldowns' => $cooldowns,
            'takes_gym_no_shows' => $this->takesGymNoShows,
            'not_found_count' => $this->countsNotFound ? $this->notFoundCount : null,
            'not_found_within_seconds' => $this->notFoundWithin,
            'burst_count' => $this->countsBursts ? $this->burstCount : null,
            'burst_within_seconds' => $this->burstWithin,
        ];

        DB::transaction(function () use (&$tarpit, $attributes) {
            $tarpit = $tarpit === null ? $this->team->tarpits()->create($attributes) : tap($tarpit)->update($attributes);
            $tarpit->domains()->sync($this->allDomains ? [] : array_map('intval', $this->domainIds));
        });
        Tarpit::forgetFeed();

        unset($this->tarpits, $this->lastDay);
        Flux::modal('tarpit-editor')->close();
        Flux::toast(variant: 'success', text: $tarpit->enabled ? __('Saved. Your edges have it within 10 seconds.') : __('Saved, switched off.'));
    }

    /**
     * Switch a tarpit on or off.
     */
    public function toggle(int $tarpitId): void
    {
        $tarpit = $this->team->tarpits()->findOrFail($tarpitId);
        Gate::authorize('update', $tarpit);

        $tarpit->update(['enabled' => ! $tarpit->enabled]);
        unset($this->tarpits);
    }

    public function confirmDelete(int $tarpitId): void
    {
        $tarpit = $this->team->tarpits()->findOrFail($tarpitId);
        Gate::authorize('delete', $tarpit);

        $this->deletingId = $tarpit->id;
        Flux::modal('delete-tarpit')->show();
    }

    /**
     * Delete the tarpit. The requests it held keep their badge, without its name.
     */
    public function delete(): void
    {
        $tarpit = $this->team->tarpits()->findOrFail($this->deletingId);
        Gate::authorize('delete', $tarpit);

        $tarpit->delete();
        $this->deletingId = null;
        unset($this->tarpits, $this->lastDay);

        Flux::modal('delete-tarpit')->close();
        Flux::toast(text: __('Tarpit deleted.'));
    }

    /**
     * Describe how long, in total, something was held, as a person says it.
     */
    public function describeHeld(int $milliseconds): string
    {
        return HashPuzzle::describeSeconds(intdiv($milliseconds, 1000));
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Make them wait')"
        :title="__('Tarpits')"
        :description="__('Clients in a tarpit get a free try, then every request waits at the edge a little longer than the last before it goes on. Scanners and scrapers that never run the gym\'s script burn their time here instead of on your origin.')"
    >
        <x-slot:actions>
            @if ($this->canManage)
                <flux:button size="sm" variant="primary" icon="plus" wire:click="create" data-test="new-tarpit">{{ __('New tarpit') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-ntb.page-heading>

    @if ($this->enforcingCount === 0)
        <flux:callout class="mb-6" variant="warning" icon="shield-exclamation" data-test="tarpit-observe-only">
            <flux:callout.text>{{ __('None of your domains is in Enforce mode, so no tarpit holds anyone yet. Switch a domain to Enforce on its page.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" :href="route('domains.index')" wire:navigate>{{ __('Domains') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @php($totals = collect($this->lastDay))
    @if ($this->tarpits->isNotEmpty())
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ntb.metric :label="__('Requests held, last 24 hours')" :value="Number::format($totals->sum('held'))" icon="clock" :href="route('activity.index', ['q' => 'tarpit.outcome = \'held\''])" data-test="tarpit-held-activity" />
            <x-ntb.metric :label="__('Clients behind them')" :value="Number::format($totals->sum('clients'))" icon="computer-desktop" />
            <x-ntb.metric :label="__('Bot time wasted')" :value="$this->describeHeld($totals->sum('held_ms'))" icon="fire" data-test="tarpit-time-wasted" />
        </div>

        <x-ntb.panel class="mb-6" data-test="tarpit-chart">
            <x-ntb.count-chart
                :buckets="$this->chartBuckets"
                :label="$chartSource === 'tarpits' ? __('Tarpits added') : __('Requests held')"
                color="bg-(--chart-spoofed)"
                height-class="h-40"
            >
                <x-slot:heading>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="inline-flex rounded-md border border-line bg-canvas p-0.5 text-xs font-medium dark:border-zinc-700 dark:bg-zinc-900" role="group" aria-label="{{ __('What to chart') }}" data-test="tarpit-chart-source">
                            @foreach (['holds' => __('Requests held'), 'tarpits' => __('Tarpits added')] as $source => $sourceLabel)
                                <button
                                    type="button"
                                    wire:click="$set('chartSource', '{{ $source }}')"
                                    @class(['rounded px-2.5 py-1 transition', 'bg-white text-ink shadow-sm dark:bg-zinc-700 dark:text-white' => $chartSource === $source, 'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => $chartSource !== $source])
                                    aria-pressed="{{ $chartSource === $source ? 'true' : 'false' }}"
                                    data-test="tarpit-chart-source-{{ $source }}"
                                >{{ $sourceLabel }}</button>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            @if (count($intervals = ChartInterval::for($this->chartPeriod)) > 1)
                                <flux:select wire:model.live="chartInterval" size="xs" class="w-32" :aria-label="__('Bar size')" data-test="tarpit-chart-interval">
                                    @foreach ($intervals as $intervalOption)
                                        <flux:select.option :value="$intervalOption === ChartInterval::default($this->chartPeriod) ? '' : $intervalOption->value">{{ $intervalOption->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            <flux:select wire:model.live="period" size="xs" class="w-36" :aria-label="__('Time period')" data-test="tarpit-chart-period">
                                @foreach (TrafficPeriod::cases() as $periodOption)
                                    <flux:select.option :value="$periodOption->value">{{ $periodOption->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    @if ($chartSource === 'holds')
                        <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.65rem] text-zinc-500 dark:text-zinc-400" data-test="tarpit-chart-heat">
                            <span>{{ __('Bar height is requests held; colour is the time they were held for —') }}</span>
                            <span class="inline-flex items-center gap-1.5">
                                {{ __('less') }}
                                <span class="h-2 w-20 rounded-full" style="background: linear-gradient(to right, color-mix(in oklab, var(--chart-spoofed) 35%, transparent), var(--chart-spoofed))"></span>
                                {{ __('more') }}
                            </span>
                        </div>
                    @endif
                </x-slot:heading>
            </x-ntb.count-chart>
        </x-ntb.panel>
    @endif

    <div class="space-y-4">
        @forelse ($this->tarpits as $tarpit)
            @php($day = $this->lastDay[$tarpit->id] ?? ['requests' => 0, 'held' => 0, 'clients' => 0, 'held_ms' => 0])
            <x-ntb.panel wire:key="tarpit-{{ $tarpit->id }}" data-test="tarpit">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:icon.clock class="size-5 text-violet-600 dark:text-violet-400" />
                            <h2 class="text-base font-semibold tracking-tight">{{ $tarpit->name }}</h2>
                            @unless ($tarpit->enabled)
                                <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                            @endunless
                        </div>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $tarpit->describeWaits() }}</p>

                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-xs text-zinc-500 sm:grid-cols-[auto_1fr] dark:text-zinc-400">
                            <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Who goes in') }}</dt>
                            <dd class="flex flex-wrap gap-1">
                                @forelse ($tarpit->rules as $rule)
                                    @php($held = $this->statusesHeldBy($rule))
                                    <a href="{{ route('rules.index', ['rule' => $rule->id]) }}" wire:navigate data-test="tarpit-rule">
                                        <flux:badge size="sm" icon="scale">
                                            {{ $rule->name }}{{ $rule->enabled ? '' : ' ('.__('off').')' }}
                                            @if ($held)
                                                <span class="ms-1 font-mono">· {{ implode(', ', $held) }}</span>
                                            @elseif ($this->holdsTheRequest($rule))
                                                <span class="ms-1">· {{ __('on the request') }}</span>
                                            @endif
                                        </flux:badge>
                                    </a>
                                @empty
                                    <span data-test="tarpit-no-rules">{{ __('No rule sends clients here yet. Add TARPIT \':name\' to one.', ['name' => $tarpit->name]) }}</span>
                                @endforelse
                                @if ($tarpit->takes_gym_no_shows)
                                    <flux:badge size="sm" icon="fire">{{ __('Gym no-shows') }}</flux:badge>
                                @endif
                                @if ($tarpit->not_found_count !== null)
                                    <flux:badge size="sm" icon="document-magnifying-glass">{{ __(':n 404s in :s s', ['n' => $tarpit->not_found_count, 's' => $tarpit->not_found_within_seconds]) }}</flux:badge>
                                @endif
                                @if ($tarpit->burst_count !== null)
                                    <flux:badge size="sm" icon="bolt">{{ __(':n requests in :s s', ['n' => $tarpit->burst_count, 's' => $tarpit->burst_within_seconds]) }}</flux:badge>
                                @endif
                            </dd>
                            <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Where') }}</dt>
                            <dd>{{ $tarpit->all_domains ? __('Every domain in Enforce mode') : $tarpit->domains->pluck('hostname')->join(', ') }}</dd>
                            <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('After the wait') }}</dt>
                            <dd>{{ $tarpit->after_wait->label() }} · {{ __('at most :n held at once, then :full', ['n' => Number::format($tarpit->max_held), 'full' => Str::lower($tarpit->when_full->label())]) }} · {{ __('forgotten after :m min quiet', ['m' => intdiv($tarpit->forget_after_seconds, 60)]) }}</dd>
                        </dl>
                    </div>

                    <div class="flex flex-col items-end gap-3">
                        <div class="text-end" data-test="tarpit-last-day">
                            <p class="text-2xl font-semibold tabular-nums">{{ $this->describeHeld($day['held_ms']) }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice('wasted on :count held request|wasted on :count held requests', $day['held'], ['count' => Number::format($day['held'])]) }}
                                {{ trans_choice('from :count client, last 24 h|from :count clients, last 24 h', $day['clients'], ['count' => Number::format($day['clients'])]) }}
                            </p>
                        </div>
                        <flux:button size="sm" icon="chart-bar" :href="route('activity.index', ['q' => 'tarpit = '.RequestQuery::quote($tarpit->name)])" wire:navigate data-test="tarpit-activity">{{ __('See activity') }}</flux:button>
                        @if ($this->canManage)
                            <div class="flex items-center gap-2">
                                <flux:switch :checked="$tarpit->enabled" wire:click="toggle({{ $tarpit->id }})" :aria-label="__('On')" data-test="tarpit-toggle" />
                                <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $tarpit->id }})" data-test="tarpit-edit">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $tarpit->id }})" :aria-label="__('Delete')" />
                            </div>
                        @endif
                    </div>
                </div>
            </x-ntb.panel>
        @empty
            <x-ntb.panel>
                <x-ntb.empty-state :heading="__('No tarpits yet.')">
                    {{ __('Rules fill tarpits. On the Rules page, start from "Error answers, to a tarpit" or "Probe paths, to a tarpit": saving one makes its tarpit, here.') }}
                    @if ($this->canManage)
                        <x-slot:actions>
                            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New tarpit') }}</flux:button>
                        </x-slot:actions>
                    @endif
                </x-ntb.empty-state>
            </x-ntb.panel>
        @endforelse
    </div>

    <flux:modal name="tarpit-editor" class="w-full md:max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? __('New tarpit') : __('Edit tarpit') }}</flux:heading>
                <flux:subheading>{{ __('Only domains in Enforce mode hold anyone.') }}</flux:subheading>
            </div>

            <div class="flex items-end gap-4">
                <flux:input wire:model="name" :label="__('Name')" class="flex-1" required maxlength="80" data-test="tarpit-name" />
                <flux:switch wire:model="enabled" :label="__('On')" />
            </div>

            <flux:fieldset>
                <flux:legend>{{ __('Who goes in') }}</flux:legend>
                <flux:text class="text-sm">
                    {{ __('Rules decide who goes in: add TARPIT \':name\' to a rule to send its clients here. Each holds its clients for as long as its verdict lasts; one request can decide (a probe path) also holds that very request, and one that reads only the status (status IN (403, 404)) holds the origin\'s answer with it.', ['name' => trim($name) !== '' ? trim($name) : __('name')]) }}
                    <flux:link :href="route('rules.index')" wire:navigate>{{ __('Rules') }} →</flux:link>
                </flux:text>
                @if ($editingId !== null)
                    @php($editing = $this->tarpits->firstWhere('id', $editingId))
                    <div class="mt-3 flex flex-wrap gap-1" data-test="tarpit-editor-rules">
                        @forelse ($editing?->rules ?? [] as $rule)
                            <a href="{{ route('rules.index', ['rule' => $rule->id]) }}" wire:navigate><flux:badge size="sm" icon="scale">{{ $rule->name }}{{ $rule->enabled ? '' : ' ('.__('off').')' }}</flux:badge></a>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No rule sends clients here yet.') }}</p>
                        @endforelse
                    </div>
                @endif

                <details class="mt-4 rounded-lg border border-line p-3 dark:border-zinc-800" @if ($takesGymNoShows || $countsNotFound || $countsBursts) open @endif data-test="tarpit-without-rule">
                    <summary class="cursor-pointer text-sm font-medium">{{ __('Also put in, without a rule') }}</summary>
                    <div class="mt-3 space-y-4">
                        <flux:checkbox wire:model="takesGymNoShows" :label="__('Gym no-shows')" :description="__('Clients sent to the gym in the last day that never ran its script (Gave up · 0 s). Browsers run it on their own.')" />

                        <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                            <flux:checkbox wire:model.live="countsNotFound" :label="__('404 collectors')" :description="__('Counted at the edge, so it works at once.')" />
                            <flux:input type="number" wire:model="notFoundCount" :label="__('404s')" min="2" class="w-24" :disabled="! $countsNotFound" />
                            <flux:input type="number" wire:model="notFoundWithin" :label="__('within (s)')" min="5" class="w-28" :disabled="! $countsNotFound" />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                            <flux:checkbox wire:model.live="countsBursts" :label="__('Bursts')" :description="__('A rate limit: too many requests from one IP, counted at the edge.')" />
                            <flux:input type="number" wire:model="burstCount" :label="__('requests')" min="5" class="w-24" :disabled="! $countsBursts" />
                            <flux:input type="number" wire:model="burstWithin" :label="__('within (s)')" min="1" class="w-28" :disabled="! $countsBursts" />
                        </div>
                    </div>
                </details>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('The wait') }}</flux:legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input type="number" wire:model="freeTries" :label="__('Free tries')" min="0" max="20" />
                    <flux:input wire:model="delays" :label="__('Then wait (s)')" placeholder="2, 8, 24" data-test="tarpit-delays" />
                    <flux:input type="number" wire:model="maxDelay" :label="__('Never longer than (s)')" min="1" :max="Tarpit::LONGEST_DELAY" />
                    <flux:input type="number" wire:model="maxHeld" :label="__('Held at once, per edge')" min="1" :max="Tarpit::MOST_HELD" :description="__('Each held request keeps a connection open.')" />
                    <flux:input type="number" wire:model="forgetMinutes" :label="__('Start over after (min quiet)')" min="1" max="1440" />
                </div>
                @if ($this->pro)
                    <flux:input wire:model="cooldowns" :label="__('Step back a try after (s quiet)')" :description="__('Optional. The quiet needed to drop one try, one per try in turn. At 10, 20, 30, 60, 120 a client five tries in drops back one after 120 s without a request, and clears in a few minutes. Blank, only the start-over time resets it.')" placeholder="10, 20, 30, 60, 120" class="mt-4 max-w-md" data-test="tarpit-cooldown" />
                @endif
            </flux:fieldset>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:radio.group wire:model="afterWait" :label="__('After the wait')">
                    @foreach (TarpitAfterWait::cases() as $after)
                        <flux:radio :value="$after->value" :label="$after->label()" :description="$after->description()" />
                    @endforeach
                </flux:radio.group>
                <flux:radio.group wire:model="whenFull" :label="__('When it\'s holding all it may')">
                    @foreach (TarpitWhenFull::cases() as $full)
                        <flux:radio :value="$full->value" :label="$full->label()" :description="$full->description()" />
                    @endforeach
                </flux:radio.group>
            </div>

            <flux:fieldset>
                <flux:legend>{{ __('Where') }}</flux:legend>
                <flux:switch wire:model.live="allDomains" :label="__('Every domain in Enforce mode')" />
                @unless ($allDomains)
                    <flux:checkbox.group wire:model="domainIds" class="mt-3 max-h-48 overflow-y-auto">
                        @foreach ($this->domains as $domain)
                            <flux:checkbox :value="$domain->id" :label="$domain->hostname.($domain->protection_mode === ProtectionMode::Enforce ? '' : ' ('.__('observe').')')" />
                        @endforeach
                    </flux:checkbox.group>
                    @error('domainIds') <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
                @endunless
            </flux:fieldset>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="tarpit-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-tarpit" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this tarpit?') }}</flux:heading>
                <flux:subheading>{{ __('Its clients stop waiting within 10 seconds. Requests it held keep their badge.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
