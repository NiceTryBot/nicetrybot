<?php

use App\Data\RequestQuery;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
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

new #[Title('Tarpits')] class extends Component
{
    /**
     * A rule to send to a new tarpit, from the Rules page's "New tarpit".
     */
    #[Url(as: 'rule', except: null)]
    public ?int $forRule = null;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public bool $enabled = true;

    public bool $allDomains = true;

    /** @var list<int|string> */
    public array $domainIds = [];

    /** @var list<int|string> */
    public array $ruleIds = [];

    public bool $takesGymNoShows = true;

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

    public function mount(): void
    {
        if ($this->forRule !== null && $this->canManage && $this->team->edgeRules()->whereKey($this->forRule)->exists()) {
            $this->create();
            $this->ruleIds = [$this->forRule];
        }

        $this->forRule = null;
    }

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
     * @return Collection<int, Tarpit>
     */
    #[Computed]
    public function tarpits(): Collection
    {
        return $this->team->tarpits()
            ->with(['rules:edge_rules.id,edge_rules.name,edge_rules.enabled', 'domains:domains.id,domains.hostname'])
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
     * The team's rules, to pick from.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\EdgeRule>
     */
    #[Computed]
    public function rules(): \Illuminate\Support\Collection
    {
        return $this->team->edgeRules()->orderBy('name')->get(['id', 'name', 'enabled']);
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
        $this->reset(['editingId', 'name', 'enabled', 'allDomains', 'domainIds', 'ruleIds', 'takesGymNoShows', 'countsNotFound', 'notFoundCount', 'notFoundWithin', 'countsBursts', 'burstCount', 'burstWithin', 'freeTries', 'delays', 'maxDelay', 'maxHeld', 'whenFull', 'afterWait', 'forgetMinutes']);
        $this->name = $this->tarpits->isEmpty() ? __('Probers') : '';

        Flux::modal('tarpit-editor')->show();
    }

    /**
     * Open the editor on one of the team's tarpits.
     */
    public function edit(int $tarpitId): void
    {
        $tarpit = $this->team->tarpits()->with(['rules:edge_rules.id', 'domains:domains.id'])->findOrFail($tarpitId);
        Gate::authorize('update', $tarpit);

        $this->resetValidation();
        $this->editingId = $tarpit->id;
        $this->name = $tarpit->name;
        $this->enabled = $tarpit->enabled;
        $this->allDomains = $tarpit->all_domains;
        $this->domainIds = $tarpit->domains->modelKeys();
        $this->ruleIds = $tarpit->rules->modelKeys();
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
            'ruleIds' => ['array'],
            'ruleIds.*' => ['integer', Rule::exists('edge_rules', 'id')->where('team_id', $this->team->id)],
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
            'domainIds.required' => __('Pick at least one domain, or cover them all.'),
        ]);

        $delays = array_map(fn (string $seconds) => max(0, min((int) trim($seconds), $this->maxDelay)), explode(',', $this->delays));

        if (! $this->countsNotFound && ! $this->countsBursts && ! $this->takesGymNoShows && $this->ruleIds === []) {
            $this->addError('ruleIds', __('Nobody would ever go in: pick a rule, take the gym\'s no-shows, or count 404s or bursts.'));

            return;
        }

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
            'takes_gym_no_shows' => $this->takesGymNoShows,
            'not_found_count' => $this->countsNotFound ? $this->notFoundCount : null,
            'not_found_within_seconds' => $this->notFoundWithin,
            'burst_count' => $this->countsBursts ? $this->burstCount : null,
            'burst_within_seconds' => $this->burstWithin,
        ];

        DB::transaction(function () use (&$tarpit, $attributes) {
            $tarpit = $tarpit === null ? $this->team->tarpits()->create($attributes) : tap($tarpit)->update($attributes);
            $tarpit->rules()->sync(array_map('intval', $this->ruleIds));
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
                                @foreach ($tarpit->rules as $rule)
                                    <a href="{{ route('rules.index', ['rule' => $rule->id]) }}" wire:navigate><flux:badge size="sm" icon="scale">{{ $rule->name }}{{ $rule->enabled ? '' : ' ('.__('off').')' }}</flux:badge></a>
                                @endforeach
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
                    {{ __('Start with one for probers: send the "Probers, for a tarpit" rule to it, take the gym\'s no-shows, and count 404s at the edge.') }}
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
                <div class="space-y-4">
                    <flux:checkbox.group wire:model="ruleIds" :label="__('Clients of these rules')" :description="__('Everyone holding one of their verdicts. A rule one request can decide (like a probe path) also holds the very request that matches.')">
                        @forelse ($this->rules as $rule)
                            <flux:checkbox :value="$rule->id" :label="$rule->name.($rule->enabled ? '' : ' ('.__('off').')')" />
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('No rules yet.') }} <flux:link :href="route('rules.index')" wire:navigate>{{ __('Write one') }}</flux:link></p>
                        @endforelse
                    </flux:checkbox.group>
                    @error('ruleIds') <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror

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
