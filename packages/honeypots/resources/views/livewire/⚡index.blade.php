<?php

use App\Actions\Analytics\CountOverTime;
use App\Data\RequestQuery;
use App\Enums\ChartInterval;
use App\Enums\DomainStatus;
use App\Enums\TrafficPeriod;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use NiceTry\Honeypots\Enums\HoneypotOutcome;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotHit;
use NiceTry\Honeypots\Models\HoneypotTrap;
use NiceTry\Honeypots\Support\TrapPatterns;

new #[Title('Honeypots')] class extends Component
{
    /**
     * How far back the chart and the most-trapped paths reach.
     */
    #[Url(except: '7d')]
    public string $period = '7d';

    /**
     * How much time each bar of the chart covers ("1h", "1d", …), or "" for the period's default.
     */
    #[Url(as: 'by', except: '')]
    public string $chartInterval = '';

    /**
     * What the chart counts: "served" (trapped 404s a honeypot answered) or "failed" (ones it couldn't,
     * so the site's own 404 went out).
     */
    #[Url(as: 'chart', except: 'served')]
    public string $chartSource = 'served';

    public ?int $editingHoneypotId = null;

    public string $name = '';

    public string $origin = '';

    public int $timeoutSeconds = 30;

    public ?int $editingTrapId = null;

    public string $patterns = '';

    /**
     * The honeypot the trap sends to. Selects send null as well as '' for no choice, so both fit.
     */
    public int|string|null $honeypotId = null;

    /**
     * The one domain the trap covers, or null ('' from some selects) for every domain of the team.
     */
    public int|string|null $domainId = null;

    public bool $enabled = true;

    /**
     * What's about to be deleted: ['honeypot' or 'trap', its id].
     *
     * @var array{0: string, 1: int}|null
     */
    public ?array $deleting = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [Honeypot::class, $this->team]);
    }

    /**
     * @return Collection<int, Honeypot>
     */
    #[Computed]
    public function honeypots(): Collection
    {
        return $this->team->honeypots()->withCount('traps')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, HoneypotTrap>
     */
    #[Computed]
    public function traps(): Collection
    {
        return $this->team->honeypotTraps()->with(['honeypot:id,name', 'domain:id,hostname,status'])->orderBy('id')->get();
    }

    /**
     * Each honeypot's answers in the last day: [honeypot id => ['served' => n, 'failed' => n]].
     *
     * @return array<int, array{served: int, failed: int}>
     */
    #[Computed]
    public function lastDay(): array
    {
        $counts = HoneypotHit::query()
            ->whereIn('honeypot_id', $this->honeypots->modelKeys())
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('honeypot_id, outcome, count(*) as hits')
            ->groupBy('honeypot_id', 'outcome')
            ->get();

        $byHoneypot = [];

        foreach ($counts as $row) {
            $byHoneypot[$row->honeypot_id] ??= ['served' => 0, 'failed' => 0];
            $byHoneypot[$row->honeypot_id][$row->outcome === HoneypotOutcome::Served ? 'served' : 'failed'] += (int) $row->hits;
        }

        return $byHoneypot;
    }

    /**
     * The team's trapped requests in the last day, across all its honeypots: how many were answered,
     * how many fell back to the site's 404, by how many clients, and how long an answer took on average.
     *
     * @return array{served: int, failed: int, clients: int, avg_ms: int}
     */
    #[Computed]
    public function totals(): array
    {
        $row = HoneypotHit::query()
            ->whereIn('honeypot_hits.honeypot_id', $this->honeypots->modelKeys())
            ->where('honeypot_hits.created_at', '>=', now()->subDay())
            ->join('edge_requests', 'edge_requests.id', '=', 'honeypot_hits.edge_request_id')
            ->toBase()
            ->first([
                DB::raw('sum(case when honeypot_hits.outcome = '.HoneypotOutcome::Served->value.' then 1 else 0 end) as served'),
                DB::raw('sum(case when honeypot_hits.outcome = '.HoneypotOutcome::Failed->value.' then 1 else 0 end) as failed'),
                DB::raw('count(distinct edge_requests.client_ip) as clients'),
                DB::raw('avg(case when honeypot_hits.outcome = '.HoneypotOutcome::Served->value.' then honeypot_hits.took_ms end) as avg_ms'),
            ]);

        return [
            'served' => (int) ($row->served ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'clients' => (int) ($row->clients ?? 0),
            'avg_ms' => (int) round((float) ($row->avg_ms ?? 0)),
        ];
    }

    /**
     * The period the chart covers, falling back to the last 7 days.
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
     * The chart's bars across the period: how many trapped requests the team's honeypots answered, or
     * how many fell back to the site's 404, by when each was imported.
     *
     * @return array<int, array{key: string, label: string, start: int, end: int, count: int}>
     */
    #[Computed]
    public function chartBuckets(): array
    {
        $outcome = $this->chartSource === 'failed' ? HoneypotOutcome::Failed : HoneypotOutcome::Served;

        return app(CountOverTime::class)->handle(
            HoneypotHit::query()
                ->whereIn('honeypot_id', $this->team->honeypots()->select('honeypots.id'))
                ->where('outcome', $outcome)
                ->toBase(),
            'honeypot_hits.created_at',
            $this->chartPeriod,
            ChartInterval::resolve($this->chartInterval, $this->chartPeriod),
        );
    }

    /**
     * The paths the team's honeypots were asked about most in the period, with how many clients asked
     * and how many times they answered.
     *
     * @return list<array{host: string, path: string, hits: int, served: int, clients: int, last_seen: string}>
     */
    #[Computed]
    public function topPaths(): array
    {
        return HoneypotHit::query()
            ->whereIn('honeypot_hits.honeypot_id', $this->honeypots->modelKeys())
            ->where('honeypot_hits.created_at', '>=', $this->chartPeriod->start(now()->toImmutable()))
            ->join('edge_requests', 'edge_requests.id', '=', 'honeypot_hits.edge_request_id')
            ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->join('domains', 'domains.id', '=', 'edge_requests.domain_id')
            ->groupBy('domains.hostname', 'request_paths.path')
            ->orderByDesc('hits')
            ->limit(15)
            ->toBase()
            ->get([
                'domains.hostname as host',
                'request_paths.path',
                DB::raw('count(*) as hits'),
                DB::raw('sum(case when honeypot_hits.outcome = '.HoneypotOutcome::Served->value.' then 1 else 0 end) as served'),
                DB::raw('count(distinct edge_requests.client_ip) as clients'),
                DB::raw('max(edge_requests.occurred_at) as last_seen'),
            ])
            ->map(fn (object $row) => [
                'host' => (string) $row->host,
                'path' => (string) $row->path,
                'hits' => (int) $row->hits,
                'served' => (int) $row->served,
                'clients' => (int) $row->clients,
                'last_seen' => (string) $row->last_seen,
            ])
            ->values()
            ->all();
    }

    /**
     * The team's domains, to pick from.
     *
     * @return Collection<int, \App\Models\Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname', 'status']);
    }

    /**
     * Open the editor for a new honeypot.
     */
    public function createHoneypot(): void
    {
        Gate::authorize('create', [Honeypot::class, $this->team]);

        $this->resetValidation();
        $this->reset(['editingHoneypotId', 'name', 'origin', 'timeoutSeconds']);

        Flux::modal('honeypot-editor')->show();
    }

    /**
     * Open the editor on one of the team's honeypots.
     */
    public function editHoneypot(int $honeypotId): void
    {
        $honeypot = $this->team->honeypots()->findOrFail($honeypotId);
        Gate::authorize('update', $honeypot);

        $this->resetValidation();
        $this->editingHoneypotId = $honeypot->id;
        $this->name = $honeypot->name;
        $this->origin = $honeypot->origin;
        $this->timeoutSeconds = $honeypot->timeout_seconds;

        Flux::modal('honeypot-editor')->show();
    }

    /**
     * Save the honeypot being edited (or the new one).
     */
    public function saveHoneypot(): void
    {
        $honeypot = $this->editingHoneypotId === null ? null : $this->team->honeypots()->findOrFail($this->editingHoneypotId);
        $honeypot === null ? Gate::authorize('create', [Honeypot::class, $this->team]) : Gate::authorize('update', $honeypot);

        $this->validate(Honeypot::editorRules());

        $attributes = [
            'name' => $this->name,
            'origin' => rtrim($this->origin, '/'),
            'timeout_seconds' => $this->timeoutSeconds,
        ];

        $honeypot === null ? $this->team->honeypots()->create($attributes) : $honeypot->update($attributes);

        unset($this->honeypots, $this->traps);
        Flux::modal('honeypot-editor')->close();
        Flux::toast(variant: 'success', text: __('Saved. Give your honeypot its secret so it knows your edges.'));
    }

    /**
     * Give a honeypot a new secret.
     */
    public function rotateSecret(int $honeypotId): void
    {
        $honeypot = $this->team->honeypots()->findOrFail($honeypotId);
        Gate::authorize('update', $honeypot);

        $honeypot->rotateSecret();

        unset($this->honeypots);
        Flux::toast(variant: 'warning', text: __('New secret issued. Your edges send it within 10 seconds: give it to the honeypot now.'));
    }

    /**
     * Open the editor for a new trap.
     */
    public function createTrap(): void
    {
        Gate::authorize('create', [HoneypotTrap::class, $this->team]);

        $this->resetValidation();
        $this->reset(['editingTrapId', 'patterns', 'domainId', 'enabled']);
        $this->honeypotId = $this->honeypots->first()?->id;

        Flux::modal('trap-editor')->show();
    }

    /**
     * Open the editor on one of the team's traps.
     */
    public function editTrap(int $trapId): void
    {
        $trap = $this->team->honeypotTraps()->findOrFail($trapId);
        Gate::authorize('update', $trap);

        $this->resetValidation();
        $this->editingTrapId = $trap->id;
        $this->patterns = implode("\n", $trap->patterns);
        $this->honeypotId = $trap->honeypot_id;
        $this->domainId = $trap->domain_id;
        $this->enabled = $trap->enabled;

        Flux::modal('trap-editor')->show();
    }

    /**
     * Save the trap being edited (or the new one). The edge has it within a few seconds.
     */
    public function saveTrap(): void
    {
        $trap = $this->editingTrapId === null ? null : $this->team->honeypotTraps()->findOrFail($this->editingTrapId);
        $trap === null ? Gate::authorize('create', [HoneypotTrap::class, $this->team]) : Gate::authorize('update', $trap);

        $this->validate([
            'patterns' => ['required', 'string', TrapPatterns::rule()],
            'honeypotId' => ['required', Rule::exists('honeypots', 'id')->where('team_id', $this->team->id)],
            'domainId' => ['nullable', Rule::exists('domains', 'id')->where('team_id', $this->team->id)],
        ], [
            'patterns.required' => __('Add at least one path pattern.'),
            'honeypotId.required' => __('Pick the honeypot these 404s go to.'),
        ]);

        $attributes = [
            'patterns' => TrapPatterns::fromText($this->patterns),
            'honeypot_id' => (int) $this->honeypotId,
            'domain_id' => blank($this->domainId) ? null : (int) $this->domainId,
            'enabled' => $this->enabled,
        ];

        $trap = $trap === null ? $this->team->honeypotTraps()->create($attributes) : tap($trap)->update($attributes);

        unset($this->traps, $this->honeypots);
        Flux::modal('trap-editor')->close();
        Flux::toast(variant: 'success', text: $trap->enabled ? __('Saved. Your edges have it within 10 seconds.') : __('Saved, switched off.'));
    }

    /**
     * Switch a trap on or off.
     */
    public function toggleTrap(int $trapId): void
    {
        $trap = $this->team->honeypotTraps()->findOrFail($trapId);
        Gate::authorize('update', $trap);

        $trap->update(['enabled' => ! $trap->enabled]);
        unset($this->traps);
    }

    public function confirmDelete(string $kind, int $id): void
    {
        $record = $this->record($kind, $id);
        Gate::authorize('delete', $record);

        $this->deleting = [$kind, $record->id];
        Flux::modal('delete-honeypot')->show();
    }

    /**
     * Delete the honeypot (and its traps) or the trap.
     */
    public function delete(): void
    {
        abort_if($this->deleting === null, 404);

        $record = $this->record(...$this->deleting);
        Gate::authorize('delete', $record);

        $record->delete();
        $this->deleting = null;
        unset($this->honeypots, $this->traps);

        Flux::modal('delete-honeypot')->close();
        Flux::toast(text: __('Deleted. Those 404s are the site’s own again within 10 seconds.'));
    }

    /**
     * One of the team's honeypots or traps.
     */
    private function record(string $kind, int $id): Honeypot|HoneypotTrap
    {
        return match ($kind) {
            'honeypot' => $this->team->honeypots()->findOrFail($id),
            'trap' => $this->team->honeypotTraps()->findOrFail($id),
            default => abort(404),
        };
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Feed the probes')"
        :title="__('Honeypots')"
        :description="__('When a bot probes a path your site doesn’t have, like /wp-admin or /api/v1/users, the edge can answer the 404 from a honeypot of yours instead, a Galah server say, and keep it busy. Pages your sites really serve are never touched, and verified search engines always get your real 404.')"
    >
        <x-slot:actions>
            @if ($this->canManage)
                <flux:button size="sm" icon="plus" wire:click="createHoneypot" data-test="new-honeypot">{{ __('New honeypot') }}</flux:button>
                @if ($this->honeypots->isNotEmpty())
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="createTrap" data-test="new-trap">{{ __('New trap') }}</flux:button>
                @endif
            @endif
        </x-slot:actions>
    </x-ntb.page-heading>

    @if ($this->honeypots->isNotEmpty())
        @php($totals = $this->totals)
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ntb.metric :label="__('Answered, last 24 hours')" :value="Number::format($totals['served'])" icon="bug-ant" data-test="honeypot-served" />
            <x-ntb.metric :label="__('Fell back to the site’s 404')" :value="Number::format($totals['failed'])" icon="arrow-uturn-left" data-test="honeypot-failed" />
            <x-ntb.metric :label="__('Clients trapped')" :value="Number::format($totals['clients'])" icon="computer-desktop" />
            <x-ntb.metric :label="__('Average answer')" :value="Number::format($totals['avg_ms'] / 1000, maxPrecision: 1).' s'" icon="clock" />
        </div>

        <x-ntb.panel class="mb-6" data-test="honeypot-chart">
            <x-ntb.count-chart
                :buckets="$this->chartBuckets"
                :label="$chartSource === 'failed' ? __('Fell back') : __('Answered')"
                :color="$chartSource === 'failed' ? 'bg-zinc-400 dark:bg-zinc-500' : 'bg-(--chart-outdated)'"
                height-class="h-40"
            >
                <x-slot:heading>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="inline-flex rounded-md border border-line bg-canvas p-0.5 text-xs font-medium dark:border-zinc-700 dark:bg-zinc-900" role="group" aria-label="{{ __('What to chart') }}" data-test="honeypot-chart-source">
                            @foreach (['served' => __('Answered'), 'failed' => __('Fell back')] as $source => $sourceLabel)
                                <button
                                    type="button"
                                    wire:click="$set('chartSource', '{{ $source }}')"
                                    @class(['rounded px-2.5 py-1 transition', 'bg-white text-ink shadow-sm dark:bg-zinc-700 dark:text-white' => $chartSource === $source, 'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => $chartSource !== $source])
                                    aria-pressed="{{ $chartSource === $source ? 'true' : 'false' }}"
                                    data-test="honeypot-chart-source-{{ $source }}"
                                >{{ $sourceLabel }}</button>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            @if (count($intervals = ChartInterval::for($this->chartPeriod)) > 1)
                                <flux:select wire:model.live="chartInterval" size="xs" class="w-32" :aria-label="__('Bar size')" data-test="honeypot-chart-interval">
                                    @foreach ($intervals as $intervalOption)
                                        <flux:select.option :value="$intervalOption === ChartInterval::default($this->chartPeriod) ? '' : $intervalOption->value">{{ $intervalOption->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            <flux:select wire:model.live="period" size="xs" class="w-36" :aria-label="__('Time period')" data-test="honeypot-chart-period">
                                @foreach (TrafficPeriod::cases() as $periodOption)
                                    <flux:select.option :value="$periodOption->value">{{ $periodOption->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                </x-slot:heading>
            </x-ntb.count-chart>
        </x-ntb.panel>

        <x-ntb.panel class="mb-8" data-test="honeypot-top-paths">
            <flux:heading>{{ __('Most trapped paths') }}</flux:heading>
            <flux:subheading>{{ __(':period, across your honeypots.', ['period' => $this->chartPeriod->label()]) }}</flux:subheading>

            @if ($this->topPaths === [])
                <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing trapped in this period yet.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-zinc-500 dark:text-zinc-400">
                            <tr>
                                <th class="py-1.5 pe-4 font-medium">{{ __('Path') }}</th>
                                <th class="py-1.5 pe-4 text-right font-medium">{{ __('Requests') }}</th>
                                <th class="py-1.5 pe-4 text-right font-medium">{{ __('Answered') }}</th>
                                <th class="py-1.5 pe-4 text-right font-medium">{{ __('Clients') }}</th>
                                <th class="py-1.5 font-medium">{{ __('Last') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line dark:divide-zinc-800">
                            @foreach ($this->topPaths as $row)
                                <tr wire:key="trapped-{{ md5($row['host'].$row['path']) }}" data-test="honeypot-top-path">
                                    <td class="max-w-md py-1.5 pe-4">
                                        <a href="{{ route('activity.index', ['q' => 'host = '.RequestQuery::quote($row['host']).' and path = '.RequestQuery::quote($row['path'])]) }}" wire:navigate class="block truncate font-mono text-xs text-ink hover:underline dark:text-white" title="{{ $row['host'].$row['path'] }}">{{ $row['path'] }}</a>
                                        <span class="text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ $row['host'] }}</span>
                                    </td>
                                    <td class="py-1.5 pe-4 text-right tabular-nums">{{ Number::format($row['hits']) }}</td>
                                    <td class="py-1.5 pe-4 text-right tabular-nums">{{ Number::format($row['served']) }}</td>
                                    <td class="py-1.5 pe-4 text-right tabular-nums">{{ Number::format($row['clients']) }}</td>
                                    <td class="py-1.5 text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Carbon::parse($row['last_seen'])->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ntb.panel>
    @endif

    <div class="space-y-8">
        <section class="space-y-4">
            <flux:heading size="lg">{{ __('Honeypots') }}</flux:heading>

            @forelse ($this->honeypots as $honeypot)
                <x-ntb.panel wire:key="honeypot-{{ $honeypot->id }}" data-test="honeypot">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $honeypot->name }}</div>
                                <div class="font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $honeypot->origin }}</div>
                            </div>

                            @php($day = $this->lastDay[$honeypot->id] ?? ['served' => 0, 'failed' => 0])
                            <dl class="grid gap-x-6 gap-y-1 text-xs text-zinc-500 sm:grid-cols-[auto_1fr] dark:text-zinc-400">
                                <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Traps') }}</dt>
                                <dd>{{ trans_choice(':count trap|:count traps', $honeypot->traps_count) }}</dd>
                                <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Last 24 hours') }}</dt>
                                <dd data-test="honeypot-last-day">{{ __(':served answered · :failed fell back to the site’s 404', $day) }}</dd>
                                <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Waits up to') }}</dt>
                                <dd>{{ trans_choice(':count second|:count seconds', $honeypot->timeout_seconds) }}</dd>
                            </dl>

                            @if ($this->canManage)
                                <div class="max-w-xl space-y-2">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <x-ntb.copy-field :label="__('Header')" value="X-NiceTry-Origin-Secret" />
                                        <x-ntb.copy-field :label="__('Secret')" :value="$honeypot->secret" secret />
                                    </div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('Your edges send this with every request they hand the honeypot. Have it refuse anything without it. On a NiceTryBot Galah droplet:') }}
                                        <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">honeypot add-secret &lt;secret&gt;</code>
                                    </p>
                                </div>
                            @endif
                        </div>

                        @if ($this->canManage)
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" icon="pencil-square" wire:click="editHoneypot({{ $honeypot->id }})" data-test="honeypot-edit">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="key" wire:click="rotateSecret({{ $honeypot->id }})" wire:confirm="{{ __('Issue a new secret? The honeypot will refuse your edges until it has the new one.') }}">{{ __('Rotate secret') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete('honeypot', {{ $honeypot->id }})" :aria-label="__('Delete')" />
                            </div>
                        @endif
                    </div>
                </x-ntb.panel>
            @empty
                <x-ntb.panel>
                    <x-ntb.empty-state :heading="__('No honeypots yet.')">
                        {{ __('A honeypot is a server of yours that makes up believable answers for any path, like Galah. Add its address here, then set traps that send it the 404s bots hit.') }}
                        @if ($this->canManage)
                            <x-slot:actions>
                                <flux:button variant="primary" icon="plus" wire:click="createHoneypot">{{ __('New honeypot') }}</flux:button>
                            </x-slot:actions>
                        @endif
                    </x-ntb.empty-state>
                </x-ntb.panel>
            @endforelse
        </section>

        @if ($this->honeypots->isNotEmpty())
            <section class="space-y-4">
                <flux:heading size="lg">{{ __('Traps') }}</flux:heading>

                @forelse ($this->traps as $trap)
                    <x-ntb.panel wire:key="trap-{{ $trap->id }}" data-test="trap">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($trap->patterns as $pattern)
                                        <code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-sm break-all dark:bg-zinc-800">{{ $pattern }}</code>
                                    @endforeach
                                    @unless ($trap->enabled)
                                        <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                                    @endunless
                                </div>

                                <dl class="mt-3 grid gap-x-6 gap-y-1 text-xs text-zinc-500 sm:grid-cols-[auto_1fr] dark:text-zinc-400">
                                    <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('404s go to') }}</dt>
                                    <dd>{{ $trap->honeypot->name }}</dd>
                                    <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Where') }}</dt>
                                    <dd data-test="trap-where">
                                        @if ($trap->isTeamWide())
                                            {{ __('Every domain') }}
                                        @else
                                            {{ $trap->domain?->hostname }}
                                            @if ($trap->domain?->status !== DomainStatus::Active)
                                                ({{ __('not active yet') }})
                                            @endif
                                        @endif
                                    </dd>
                                </dl>
                            </div>

                            @if ($this->canManage)
                                <div class="flex items-center gap-2">
                                    <flux:switch :checked="$trap->enabled" wire:click="toggleTrap({{ $trap->id }})" :aria-label="__('On')" data-test="trap-toggle" />
                                    <flux:button size="sm" icon="pencil-square" wire:click="editTrap({{ $trap->id }})" data-test="trap-edit">{{ __('Edit') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete('trap', {{ $trap->id }})" :aria-label="__('Delete')" />
                                </div>
                            @endif
                        </div>
                    </x-ntb.panel>
                @empty
                    <x-ntb.panel>
                        <x-ntb.empty-state :heading="__('No traps yet.')">
                            {{ __('A trap names the paths whose 404s go to a honeypot, like api/*, admin/* or wp-login.php.') }}
                            @if ($this->canManage)
                                <x-slot:actions>
                                    <flux:button variant="primary" icon="plus" wire:click="createTrap">{{ __('New trap') }}</flux:button>
                                </x-slot:actions>
                            @endif
                        </x-ntb.empty-state>
                    </x-ntb.panel>
                @endforelse
            </section>
        @endif
    </div>

    <flux:modal name="honeypot-editor" class="w-full md:max-w-xl">
        <form wire:submit="saveHoneypot" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingHoneypotId === null ? __('New honeypot') : __('Edit honeypot') }}</flux:heading>
                <flux:subheading>{{ __('Your edges hand it trapped requests as they came, with the site’s name in X-Forwarded-Host.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required maxlength="100" placeholder="Galah" data-test="honeypot-name" />
            <flux:input wire:model="origin" :label="__('Address')" :description="__('Where it answers, like https://203-0-113-7.sslip.io. Only public addresses are reached.')" required type="url" maxlength="2048" placeholder="https://" data-test="honeypot-origin" />
            <flux:input wire:model="timeoutSeconds" :label="__('Wait up to (seconds)')" :description="__('A honeypot that makes pages up with a model can take a few seconds. Past this, the site’s own 404 goes out.')" type="number" min="1" max="{{ Honeypot::MAX_TIMEOUT }}" required data-test="honeypot-timeout" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="honeypot-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="trap-editor" class="w-full md:max-w-xl">
        <form wire:submit="saveTrap" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingTrapId === null ? __('New trap') : __('Edit trap') }}</flux:heading>
                <flux:subheading>{{ __('Only 404s are sent: a path your site serves is always your site’s.') }}</flux:subheading>
            </div>

            <flux:textarea wire:model="patterns" :label="__('Paths')" rows="5" required placeholder="api/*&#10;admin/*&#10;wp-login.php&#10;re:\.(env|git)" data-test="trap-patterns"
                :description="__('One per line. * matches anything, slashes too, in any case. Start a line with re: for a regular expression matched anywhere in the path.')" />

            <flux:select wire:model="honeypotId" :label="__('404s go to')" data-test="trap-honeypot">
                @foreach ($this->honeypots as $honeypot)
                    <flux:select.option :value="$honeypot->id">{{ $honeypot->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="domainId" :label="__('Where')" data-test="trap-domain">
                <flux:select.option value="">{{ __('Every domain') }}</flux:select.option>
                @foreach ($this->domains as $domain)
                    <flux:select.option :value="$domain->id">{{ $domain->hostname }}{{ $domain->status === DomainStatus::Active ? '' : ' ('.__('not active yet').')' }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('domainId') <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror

            <flux:switch wire:model="enabled" :label="__('On')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="trap-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-honeypot" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ ($deleting[0] ?? null) === 'honeypot' ? __('Delete this honeypot and its traps?') : __('Delete this trap?') }}</flux:heading>
                <flux:subheading>{{ __('Those 404s are the site’s own again within 10 seconds.') }}</flux:subheading>
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
