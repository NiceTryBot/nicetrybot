<?php

use App\Actions\Ai\TranslateRule;
use App\Actions\Rules\BacktestRule;
use App\Actions\Rules\FindStaleVerdicts;
use App\Actions\Rules\SaveEdgeRule;
use App\Data\RuleBacktest;
use App\Data\RuleScript;
use App\Data\RuleScriptParser;
use App\Data\RuleScriptSyntaxError;
use App\Enums\AiProvider;
use App\Enums\DomainStatus;
use App\Enums\PathCategory;
use App\Enums\ProtectionMode;
use App\Enums\TrafficPeriod;
use App\Enums\ViewSlot;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\Verdict;
use App\Support\AnthropicException;
use App\Support\RuleBoardColumn;
use App\Support\RuleDestination;
use App\Support\RuleDestinationError;
use App\Support\RuleDestinations;
use App\Support\RuleFieldDefinition;
use App\Support\RuleFields;
use App\Support\RuleTemplates;
use App\Support\ViewSlots;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Rules')] class extends Component
{
    #[Url(except: 'rules')]
    public string $tab = 'rules';

    /**
     * How the rules are laid out: the board, by what happens to their clients, or the list.
     */
    #[Url(except: 'board')]
    public string $view = 'board';

    public bool $isEditing = false;

    #[Url(as: 'rule')]
    public ?int $editingId = null;

    public string $name = '';

    public string $source = '';

    public bool $enabled = true;

    public ?string $savedAt = null;

    /**
     * Verdicts the saved rule set that its new version no longer matches, offered for lifting.
     *
     * @var list<int>
     */
    public array $staleVerdictIds = [];

    /**
     * The rule to write, in plain English, for Claude.
     */
    public string $question = '';

    public ?string $askNote = null;

    public ?string $askError = null;

    /**
     * The source the backtest was last asked for. The editor opens without it and its placeholder asks
     * for the dry run, so a slow one never holds up opening or editing a rule.
     */
    #[Locked]
    public ?string $backtestedSource = null;

    public function mount(): void
    {
        if ($this->editingId !== null) {
            $this->edit($this->editingId);
        } elseif (is_string($draft = request()->query('draft')) && $this->canManage) {
            $this->newRule();
            $this->source = Str::limit($draft, 5000, '');
            $this->name = is_string($name = request()->query('name')) ? Str::limit(trim($name), 100, '') : '';
        }
    }

    /**
     * Re-render when a client is named, so the new name shows.
     */
    #[On('label-changed')]
    public function refreshClientNames(): void
    {
        //
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [EdgeRule::class, $this->team]);
    }

    /**
     * @return Collection<int, EdgeRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return $this->team->edgeRules()
            ->withCount(['verdicts as active_verdicts_count' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();
    }

    /**
     * The rules by what happens to their clients. Editing a rule needs the editor, beside the list.
     *
     * @return list<array{column: RuleBoardColumn, cards: list<array{rule: EdgeRule, destinations: list<RuleDestination>, links: list<RuleDestination>}>}>
     */
    #[Computed]
    public function board(): array
    {
        return app(RuleDestinations::class)->board($this->team, $this->rules);
    }

    /**
     * Determine if the page shows the board. A team without rules gets the list, for its templates. The
     * board stays put while a rule is edited: the editor opens over it in a modal.
     */
    #[Computed]
    public function showsBoard(): bool
    {
        return $this->view !== 'list' && $this->rules->isNotEmpty();
    }

    /**
     * Closing the editor modal (its backdrop, escape, or the close button) clears the draft.
     */
    public function updatedIsEditing(bool $value): void
    {
        if (! $value) {
            $this->cancel();
        }
    }

    /**
     * @return Collection<int, Verdict>
     */
    #[Computed]
    public function verdicts(): Collection
    {
        return $this->team->verdicts()->active()->with(['rule', 'ja4Fingerprint'])->latest('last_matched_at')->limit(200)->get();
    }

    /**
     * How many verdicts are active, for the tab: counted, not loaded.
     */
    #[Computed]
    public function activeVerdictCount(): int
    {
        return $this->team->verdicts()->active()->count();
    }

    /**
     * The script being edited, parsed, or the syntax error that stops it parsing.
     *
     * @return array{script: ?RuleScript, error: ?array{message: string, line: int, column: int}}
     */
    #[Computed]
    public function parsed(): array
    {
        if (trim($this->source) === '') {
            return ['script' => null, 'error' => null];
        }

        try {
            return ['script' => RuleScript::parse($this->source), 'error' => null];
        } catch (RuleScriptSyntaxError $error) {
            return ['script' => null, 'error' => ['message' => $error->getMessage(), 'line' => $error->scriptLine, 'column' => $error->scriptColumn]];
        }
    }

    /**
     * A link to Activity listing the traffic the rule in the editor acts on, over the backtest's week,
     * or null while it doesn't parse.
     */
    #[Computed]
    public function activityUrl(): ?string
    {
        if ($this->parsed['script'] === null) {
            return null;
        }

        return route('activity.index', ['q' => (new RuleScriptParser($this->source))->activitySearch(), 'period' => TrafficPeriod::Week->value]);
    }

    /**
     * Whether the user connected an Anthropic key, so rules can be asked for in plain English.
     */
    #[Computed]
    public function hasAnthropicKey(): bool
    {
        return Auth::user()->aiCredentials()->where('provider', AiProvider::Anthropic)->exists();
    }

    /**
     * @return Collection<int, GymRoutine>
     */
    #[Computed]
    public function allGymRoutines(): Collection
    {
        return $this->team->gymRoutines()->get();
    }

    /**
     * Where the open saved rule sends its clients now, for the read-only summary: the destinations and
     * links the board reads, so the editor and the board agree. A rule the script hasn't saved yet (no id)
     * has none.
     *
     * @return list<RuleDestination>
     */
    #[Computed]
    public function ruleDestinations(): array
    {
        if ($this->editingId === null) {
            return [];
        }

        $rule = $this->team->edgeRules()->find($this->editingId);

        return $rule === null ? [] : app(RuleDestinations::class)->forRule($this->team, $rule);
    }

    /**
     * Determine if any of the team's domains sends visitors to the gym: only domains in Enforce mode do.
     */
    #[Computed]
    public function hasEnforcingDomain(): bool
    {
        return $this->team->domains()->where('protection_mode', ProtectionMode::Enforce)->where('status', DomainStatus::Active)->exists();
    }

    /**
     * The dry run of the script in the editor, once it's been asked for (or it's still cached from a
     * few minutes ago), kept five minutes per team and source.
     */
    #[Computed]
    public function backtest(): ?RuleBacktest
    {
        $script = $this->parsed['script'];
        $key = 'rules:backtest:'.$this->team->id.':'.md5($this->source);

        if ($script === null || ($this->backtestedSource !== $this->source && ! Cache::has($key))) {
            return null;
        }

        return new RuleBacktest(...Cache::remember($key, 300, fn (): array => get_object_vars(app(BacktestRule::class)->handle($this->team, $script))));
    }

    /**
     * Run the backtest for the script in the editor.
     */
    public function loadBacktest(): void
    {
        $this->backtestedSource = $this->source;
    }

    public function newRule(?string $template = null): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $starter = app(RuleTemplates::class)->find($template);

        $this->resetErrorBag();
        $this->fill([
            'isEditing' => true,
            'editingId' => null,
            'name' => $starter['name'] ?? '',
            'source' => $starter['source'] ?? "BLOCK ip, ja4 FOR 1 DAY\nWHERE ",
            'enabled' => true,
            'savedAt' => null,
            'staleVerdictIds' => [],
            'askNote' => null,
        ]);
        $this->dispatch('rule-loaded');
    }

    public function edit(int $ruleId): void
    {
        $rule = $this->team->edgeRules()->findOrFail($ruleId);

        $this->resetErrorBag();
        $this->fill([
            'isEditing' => true,
            'editingId' => $rule->id,
            'name' => $rule->name,
            'source' => $rule->source,
            'enabled' => $rule->enabled,
            'savedAt' => null,
            'staleVerdictIds' => [],
            'askNote' => null,
        ]);
        $this->dispatch('rule-loaded');
    }

    public function save(FindStaleVerdicts $findStaleVerdicts, SaveEdgeRule $saveEdgeRule): void
    {
        $rule = $this->editingId === null ? null : $this->team->edgeRules()->findOrFail($this->editingId);

        $rule === null
            ? Gate::authorize('create', [EdgeRule::class, $this->team])
            : Gate::authorize('update', $rule);

        $this->validate(['name' => ['required', 'string', 'max:100']]);

        $script = $this->parsed['script'];

        if ($script === null) {
            $error = $this->parsed['error'];
            $this->addError('source', $error === null ? __('Write a rule first.') : __('Line :line: :message', ['line' => $error['line'], 'message' => $error['message']]));

            return;
        }

        try {
            $rule = $saveEdgeRule->handle($this->team, $rule, ['name' => $this->name, 'source' => $this->source, 'enabled' => $this->enabled], $script);
        } catch (RuleDestinationError $error) {
            $this->addError('source', $error->getMessage());

            return;
        }

        $this->editingId = $rule->id;
        $this->staleVerdictIds = $findStaleVerdicts->handle($rule)->modelKeys();
        $this->savedAt = now()->format('H:i:s');
        unset($this->rules, $this->ruleDestinations, $this->allGymRoutines);
    }

    public function toggle(int $ruleId): void
    {
        $rule = $this->team->edgeRules()->findOrFail($ruleId);
        Gate::authorize('update', $rule);

        $rule->update(['enabled' => ! $rule->enabled]);

        if ($this->editingId === $rule->id) {
            $this->enabled = $rule->enabled;
        }

        unset($this->rules);
    }

    public function delete(int $ruleId): void
    {
        $rule = $this->team->edgeRules()->findOrFail($ruleId);
        Gate::authorize('delete', $rule);

        $rule->delete();

        if ($this->editingId === $ruleId) {
            $this->reset('isEditing', 'editingId', 'name', 'source', 'enabled', 'savedAt', 'staleVerdictIds', 'askNote');
        }

        unset($this->rules);
    }

    public function lift(int $verdictId): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $this->team->verdicts()->findOrFail($verdictId)->update(['lifted_at' => now()]);

        unset($this->verdicts, $this->activeVerdictCount, $this->rules);
    }

    /**
     * Lift the verdicts the rule's new version no longer matches.
     */
    public function liftStale(): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $this->team->verdicts()->active()->whereKey($this->staleVerdictIds)->update(['lifted_at' => now()]);
        $this->staleVerdictIds = [];

        unset($this->verdicts, $this->activeVerdictCount, $this->rules);
    }

    /**
     * Keep the verdicts the rule's new version no longer matches; they last until they expire.
     */
    public function keepStale(): void
    {
        $this->staleVerdictIds = [];
    }

    /**
     * Have Claude write a rule from the question into the editor: a fresh draft, or the unsaved draft
     * already there when the question reads as a change to it. The destination (the gym, a tarpit, the
     * blackhole) is written into the script itself, so there's nothing to apply on top of it.
     */
    public function ask(TranslateRule $translateRule): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $this->reset('askNote', 'askError');

        if (trim($this->question) === '') {
            return;
        }

        $credential = Auth::user()->aiCredential(AiProvider::Anthropic);

        if ($credential === null) {
            $this->askError = __('Add your Anthropic API key in Settings → Artificial Intelligence, and Claude writes rules from plain English.');

            return;
        }

        $isRefining = $this->isEditing && $this->editingId === null;

        try {
            $translated = $translateRule->handle(
                $credential,
                $this->question,
                $this->team->domains()->orderBy('hostname')->pluck('hostname')->all(),
                $this->allGymRoutines->map(fn (GymRoutine $routine) => ['id' => $routine->id, 'name' => $routine->name, 'entry' => $routine->entry()->describe($this->team)])->values()->all(),
                $isRefining ? $this->source : '',
                app(App\Support\RuleDestinationHandlers::class)->keywords(),
            );
        } catch (AnthropicException $exception) {
            $this->askError = $exception->getMessage();

            return;
        }

        if ($translated->source === '') {
            $this->askError = $translated->note ?? __('Claude couldn’t make a rule of that. Try saying who to catch, like a path, a site or a network.');

            return;
        }

        if (! $isRefining) {
            $this->newRule();
        }

        $this->tab = 'rules';
        $this->name = $translated->name !== '' ? $translated->name : $this->name;
        $this->source = $translated->source;
        $this->askNote = $translated->note;
        $this->question = '';

        $this->dispatch('rule-loaded');
    }

    public function cancel(): void
    {
        $this->reset('isEditing', 'editingId', 'name', 'source', 'enabled', 'savedAt', 'staleVerdictIds', 'askNote');
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Your edge, your rules')"
        :title="__('Rules')"
        :description="__('Write what the edge should do, in a language that reads like SQL. Every rule is tested against your real traffic before it runs.')"
    >
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" size="sm" icon="plus" wire:click="newRule" data-test="new-rule">{{ __('New rule') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-ntb.page-heading>

    {{-- Ask: a rule in plain English, written into the editor by Claude. While a rule is open, this moves
         into the editor modal so the draft can be refined there. --}}
    @if ($this->canManage && ! $isEditing)
        <x-ntb.rule-ask class="mb-6" :has-key="$this->hasAnthropicKey" :is-editing="$isEditing" :editing-id="$editingId" :ask-error="$askError" />
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div class="max-w-full">
            <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" :aria-label="__('Rules or verdicts')">
                <flux:radio value="rules" :label="__('Rules').' · '.$this->rules->count()" />
                <flux:radio value="verdicts" :label="__('Active verdicts').' · '.Number::format($this->activeVerdictCount)" />
            </flux:radio.group>
        </div>
        @if ($tab !== 'verdicts' && $this->rules->isNotEmpty())
            <flux:radio.group wire:model.live="view" variant="segmented" size="sm" :aria-label="__('Board or list')" data-test="rules-view">
                <flux:radio value="board" :label="__('Board')" />
                <flux:radio value="list" :label="__('List')" />
            </flux:radio.group>
        @endif
    </div>

    @if ($tab === 'verdicts')
        <x-ntb.panel :heading="__('Active verdicts')" :subheading="__('Subjects your rules have banned or tagged, until the verdict expires or you lift it. Domains in observe mode record verdicts without blocking.')" :padded="false">
            @if ($this->verdicts->isEmpty())
                <x-ntb.empty-state :heading="__('No active verdicts.')">
                    {{ __('When an enabled rule matches new traffic, the subjects it blocks or tags show up here.') }}
                </x-ntb.empty-state>
            @else
                <div class="px-4 pb-2">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Subject') }}</flux:table.column>
                            <flux:table.column>{{ __('Verdict') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('Last matched') }}</flux:table.column>
                            <flux:table.column>{{ __('Expires') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->verdicts as $verdict)
                                <flux:table.row :key="'verdict-'.$verdict->id" data-test="verdict-row">
                                    <flux:table.cell class="font-mono text-xs">
                                        @if ($verdict->client_ip)
                                            <span class="group/label flex items-center gap-1">
                                                <a href="{{ route('activity.index', ['ip' => $verdict->client_ip, 'period' => '7d']) }}" wire:navigate class="hover:underline"><x-ntb.label kind="ip" :value="$verdict->client_ip" /></a>
                                                <x-ntb.label-button kind="ip" :value="$verdict->client_ip" />
                                            </span>
                                        @endif
                                        @if ($verdict->ja4Fingerprint)
                                            <span class="group/label mt-0.5 flex items-center gap-1 text-[0.65rem] text-zinc-500 dark:text-zinc-400">
                                                <a href="{{ route('activity.index', ['ja4' => $verdict->ja4Fingerprint->value, 'period' => '7d']) }}" wire:navigate class="flex items-center gap-1 hover:underline">
                                                    <flux:icon.finger-print variant="micro" class="size-3" /><x-ntb.label kind="ja4" :value="$verdict->ja4Fingerprint->value" />
                                                </a>
                                                <x-ntb.label-button kind="ja4" :value="$verdict->ja4Fingerprint->value" />
                                            </span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$verdict->action->color()">{{ $verdict->action->label() }}{{ $verdict->tag ? ' · '.$verdict->tag : '' }}</flux:badge>
                                        <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ $verdict->rule?->name ?? __('Deleted rule') }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums">{{ Number::format($verdict->matched_requests) }}</flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">{{ $verdict->last_matched_at->diffForHumans(short: true) }}</flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap">{{ $verdict->expires_at ? __('in :time', ['time' => $verdict->expires_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true)]) : __('never') }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        @if ($this->canManage)
                                            <flux:button size="xs" variant="ghost" wire:click="lift({{ $verdict->id }})" data-test="lift-verdict">{{ __('Lift') }}</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </x-ntb.panel>
    @elseif ($this->showsBoard)
        <x-ntb.rule-board :board="$this->board" :can-manage="$this->canManage" />
    @else
        {{-- The list of rules, with templates and add-on links. Opening one brings the editor modal. --}}
        <div class="max-w-xl space-y-3" data-test="rule-list">
                @forelse ($this->rules as $rule)
                    @php($ruleScript = rescue(fn () => $rule->script(), report: false))
                    {{-- The whole card opens the rule, except its switch and gym links. The open card stays put, so unsaved edits survive a stray click. --}}
                    <div
                        wire:key="rule-{{ $rule->id }}"
                        x-data="{ opening: false }"
                        @if ($editingId !== $rule->id)
                            x-on:click="if (opening || $event.target.closest('a, [data-flux-switch]')) return; opening = true; $wire.edit({{ $rule->id }}).finally(() => opening = false)"
                        @endif
                        x-bind:class="opening && 'border-coral-300 ring-2 ring-coral-400/30'"
                        @class([
                            'relative rounded-xl border bg-white p-4 transition dark:bg-zinc-900',
                            'border-coral-400 ring-1 ring-coral-400/40' => $editingId === $rule->id,
                            'cursor-pointer border-line hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700' => $editingId !== $rule->id,
                        ])
                        data-test="rule-card"
                    >
                        <div x-show="opening" x-cloak class="absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-xl bg-white/75 text-sm font-medium text-coral-700 dark:bg-zinc-900/75 dark:text-coral-300" data-test="rule-card-opening">
                            <flux:icon.loading class="size-5" />{{ __('Opening…') }}
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <button type="button" class="min-w-0 text-start">
                                <span class="block truncate font-semibold">{{ $rule->name }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" :color="$rule->action->color()">
                                        {{ $rule->action->label() }} · {{ $rule->durationLabel() }}
                                    </flux:badge>
                                    @if ($rule->active_verdicts_count > 0)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count active verdict|:count active verdicts', $rule->active_verdicts_count) }}</span>
                                    @endif
                                </span>
                            </button>
                            @if ($this->canManage)
                                <flux:switch :checked="$rule->enabled" wire:click="toggle({{ $rule->id }})" :aria-label="__('Enable :name', ['name' => $rule->name])" />
                            @endif
                        </div>
                        @if ($ruleScript)
                            <p class="mt-2 line-clamp-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $ruleScript->describe() }}</p>
                        @else
                            {{-- A saved rule stops parsing when the add-on that brings one of its fields is removed. --}}
                            <p class="mt-2 text-xs text-rose-700 dark:text-rose-300" data-test="rule-card-unparsed">{{ __('Doesn’t parse any more, so it doesn’t run. Open it to see why.') }}</p>
                        @endif
                        @php($ruleRoutines = $this->allGymRoutines->filter(fn (GymRoutine $routine) => $routine->entry()->takesVerdictsFrom($rule->id)))
                        @if ($ruleRoutines->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1.5" data-test="rule-card-gym">
                                @foreach ($ruleRoutines as $routine)
                                    <a
                                        href="{{ route('gym.routines.edit', $routine) }}"
                                        wire:navigate
                                        wire:key="rule-{{ $rule->id }}-gym-{{ $routine->id }}"
                                        title="{{ __('Open this gym routine') }}"
                                        @class([
                                            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium transition',
                                            'border-coral-200 bg-coral-50 text-coral-700 hover:border-coral-400 dark:border-coral-500/30 dark:bg-coral-500/10 dark:text-coral-300' => $routine->enabled,
                                            'border-line text-zinc-500 hover:border-zinc-300 dark:border-zinc-700 dark:text-zinc-400' => ! $routine->enabled,
                                        ])
                                    >
                                        <flux:icon.fire variant="micro" class="size-3" />{{ $routine->name }}{{ $routine->enabled ? '' : ' · '.__('off') }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        <p class="mt-2 text-[0.65rem] text-zinc-400">
                            {{ $rule->enabled ? ($rule->last_evaluated_at ? __('Checked :time', ['time' => $rule->last_evaluated_at->diffForHumans()]) : __('Waiting for its first run')) : __('Off') }}
                        </p>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-line p-4 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        {{ __('No rules yet. Start from a template, or write your own.') }}
                    </div>
                @endforelse

                @if ($this->canManage)
                    <div class="pt-2">
                        <p class="mb-2 font-mono text-[0.65rem] tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('Start from a template') }}</p>
                        <div class="grid gap-2">
                            @foreach (app(RuleTemplates::class)->all() as $key => $template)
                                <button type="button" wire:click="newRule('{{ $key }}')" wire:key="template-{{ $key }}" class="rounded-lg border border-line px-3 py-2 text-start transition hover:border-coral-400 dark:border-zinc-800 dark:hover:border-coral-500" data-test="rule-template">
                                    <span class="block text-sm font-medium">{{ __($template['name']) }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __($template['blurb']) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Add-ons' links and panels, from App\Support\ViewSlots. --}}
                    {{ app(ViewSlots::class)->render(ViewSlot::RulesGallery, ['team' => $this->team]) }}
                @endif
            </div>
    @endif

    {{-- The editor opens in a modal over the board or list, so what you were looking at stays in view. --}}
    <flux:modal name="rule-editor" wire:model="isEditing" class="w-full max-w-4xl" data-test="rule-editor">
        @if ($isEditing)
            @php($parsed = $this->parsed)
            @php($lines = max(1, substr_count($source, "\n") + 1))

            <div class="mb-5">
                <flux:heading size="lg">{{ $editingId ? __('Edit rule') : __('New rule') }}</flux:heading>
                <flux:subheading>{{ __('Rules read like SQL: BLOCK who, FOR how long, WHERE something is true. Add WITHIN and HAVING to count requests over a window, and UNTIL to lift a verdict early. FINGERPRINT tags the client and fingerprints their browser on a key page, then lets them carry on.') }}</flux:subheading>
            </div>

            @if ($this->canManage)
                <x-ntb.rule-ask class="mb-5" :has-key="$this->hasAnthropicKey" :is-editing="$isEditing" :editing-id="$editingId" :ask-error="$askError" />
            @endif

            <x-ntb.panel>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-48 flex-1">
                                <flux:input wire:model="name" :label="__('Rule name')" size="sm" :disabled="! $this->canManage" data-test="rule-name" />
                            </div>
                            <flux:switch wire:model="enabled" :label="__('Enabled')" :disabled="! $this->canManage" />
                        </div>

                        <div class="mt-4" x-data="ruleEditor(@js(app(RuleFields::class)->names()))" x-on:rule-loaded.window="$nextTick(() => sync())">
                            <div @class([
                                'relative flex overflow-hidden rounded-lg border bg-zinc-950 font-mono text-[13px] leading-6',
                                'border-rose-500/70' => $parsed['error'],
                                'border-emerald-500/50' => $parsed['script'],
                                'border-zinc-700' => ! $parsed['error'] && ! $parsed['script'],
                            ])>
                                <div class="shrink-0 border-e border-zinc-800 bg-zinc-900 px-2 py-3 text-end text-zinc-600 select-none" aria-hidden="true">
                                    @for ($line = 1; $line <= $lines; $line++)
                                        <div @class(['px-1', 'rounded bg-rose-500/20 text-rose-300' => ($parsed['error']['line'] ?? null) === $line])>{{ $line }}</div>
                                    @endfor
                                </div>
                                <div class="relative min-w-0 flex-1">
                                    <pre x-ref="overlay" wire:ignore aria-hidden="true" class="pointer-events-none absolute inset-0 m-0 overflow-hidden px-3 py-3 font-mono text-[13px] leading-6 whitespace-pre-wrap break-words text-zinc-100" x-html="html"></pre>
                                    <textarea
                                        x-ref="input"
                                        wire:model.live.debounce.450ms="source"
                                        x-on:input="sync()"
                                        x-on:scroll="scroll()"
                                        spellcheck="false"
                                        autocapitalize="off"
                                        rows="{{ max(7, $lines + 1) }}"
                                        @disabled(! $this->canManage)
                                        class="relative block w-full resize-y bg-transparent px-3 py-3 font-mono text-[13px] leading-6 whitespace-pre-wrap break-words text-zinc-100 caret-coral-400 outline-none selection:bg-coral-500/30"
                                        x-bind:class="{ '!text-transparent': ready }"
                                        aria-label="{{ __('Rule script') }}"
                                        data-test="rule-source"
                                    >{{ $source }}</textarea>
                                </div>
                                @if ($activityUrl = $this->activityUrl)
                                    <a
                                        href="{{ $activityUrl }}"
                                        target="_blank"
                                        rel="noopener"
                                        title="{{ __('List the traffic this rule acts on, over the last 7 days, in a new tab') }}"
                                        class="absolute end-2 top-2 z-10 inline-flex items-center gap-1.5 rounded-md border border-zinc-700 bg-zinc-900/90 px-2 py-1 font-sans text-xs font-medium text-zinc-300 shadow-sm backdrop-blur transition hover:border-zinc-500 hover:text-white"
                                        data-test="rule-view-in-activity"
                                    >
                                        <flux:icon.chart-bar variant="micro" class="size-3.5" />{{ __('View in Activity') }}<flux:icon.arrow-top-right-on-square variant="micro" class="size-3" />
                                    </a>
                                @endif
                            </div>

                            {{-- Reads as / error --}}
                            <div class="mt-2 text-sm" wire:loading.class="opacity-60" wire:target="source">
                                @if ($parsed['error'])
                                    <p class="flex gap-2 text-rose-700 dark:text-rose-300" data-test="rule-error">
                                        <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                        <span><span class="font-mono text-xs">{{ __('Line :line, column :column', ['line' => $parsed['error']['line'], 'column' => $parsed['error']['column']]) }}</span> · {{ $parsed['error']['message'] }}</span>
                                    </p>
                                @elseif ($parsed['script'])
                                    <p class="flex gap-2 text-zinc-700 dark:text-zinc-200" data-test="rule-reads-as">
                                        <flux:icon.chat-bubble-left-ellipsis variant="mini" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        <span><span class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Reads as') }}</span> {{ $parsed['script']->describe() }}</span>
                                    </p>
                                @endif
                                @error('source') <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
                                @if ($askNote)
                                    <p class="mt-1 flex gap-2 text-sm text-amber-800 dark:text-amber-200" data-test="rule-ask-note">
                                        <flux:icon.information-circle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                        <span>{{ $askNote }}</span>
                                    </p>
                                @endif
                            </div>

                            {{-- Palette --}}
                            @if ($this->canManage)
                                <details class="mt-3 rounded-lg border border-line px-3 py-2 dark:border-zinc-800" open>
                                    <summary class="cursor-pointer text-xs font-medium text-zinc-600 select-none dark:text-zinc-300">{{ __('Fields, categories and clauses: click to insert') }}</summary>
                                    <div class="mt-3 space-y-3 text-xs">
                                        @foreach (collect(app(RuleFields::class)->all())->groupBy(fn (RuleFieldDefinition $field) => $field->group()) as $group => $fields)
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ $group }}</span>
                                                @foreach ($fields as $field)
                                                    <button type="button" x-on:click="insert(@js($field->value))" title="{{ $field->englishName() }}{{ $field->hint() ? ' · '.$field->hint() : '' }}" class="rounded border border-line px-1.5 py-0.5 font-mono text-sky-700 hover:border-sky-400 dark:border-zinc-700 dark:text-sky-300">{{ $field->value }}</button>
                                                @endforeach
                                            </div>
                                        @endforeach
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ __('Categories') }}</span>
                                            @foreach (['probe', 'asset'] as $family)
                                                <button type="button" x-on:click="insert(@js("category = '{$family}'"))" class="rounded border border-line px-1.5 py-0.5 font-mono text-amber-700 hover:border-amber-400 dark:border-zinc-700 dark:text-amber-300">'{{ $family }}'</button>
                                            @endforeach
                                            @foreach (PathCategory::cases() as $category)
                                                <button type="button" x-on:click="insert(@js("category = '{$category->slug()}'"))" title="{{ $category->description() }}" class="rounded border border-line px-1.5 py-0.5 font-mono text-amber-700 hover:border-amber-400 dark:border-zinc-700 dark:text-amber-300">'{{ $category->slug() }}'</button>
                                            @endforeach
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ __('Clauses') }}</span>
                                            @foreach (['AND', 'OR', 'NOT', "LIKE '%…%'", 'IN (…)', 'IS NULL', 'EVERY 1 HOUR', 'WITHIN 10 MINUTES', 'HAVING COUNT(*) >= 5', 'COUNT(DISTINCT host)', "COUNT(*) FILTER (WHERE path = '/') = 0", "UNTIL status = 200", 'FOR 1 DAY', 'FOREVER'] as $clause)
                                                <button type="button" x-on:click="insert(@js($clause))" class="rounded border border-line px-1.5 py-0.5 font-mono text-coral-700 hover:border-coral-400 dark:border-zinc-700 dark:text-coral-300">{{ $clause }}</button>
                                            @endforeach
                                        </div>
                                        @php($destinationHandlers = app(App\Support\RuleDestinationHandlers::class)->all())
                                        @if ($destinationHandlers !== [])
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ __('Destinations') }}</span>
                                                @foreach ($destinationHandlers as $handler)
                                                    @php($snippet = $handler->takesTarget() ? $handler->keyword()." '…'" : $handler->keyword())
                                                    <button type="button" x-on:click="insert(@js($snippet))" class="rounded border border-line px-1.5 py-0.5 font-mono text-emerald-700 hover:border-emerald-400 dark:border-zinc-700 dark:text-emerald-300">{{ $snippet }}</button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-line pt-4 dark:border-zinc-800">
                            @if ($this->canManage)
                                <flux:button variant="primary" size="sm" wire:click="save" data-test="save-rule">{{ $editingId ? __('Save rule') : __('Create rule') }}</flux:button>
                                @if ($editingId)
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this rule? Its verdicts stay until they expire.') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            @endif
                            <flux:button variant="ghost" size="sm" wire:click="cancel">{{ __('Close') }}</flux:button>
                            @if ($savedAt)
                                <span class="text-xs text-emerald-700 dark:text-emerald-400" data-test="rule-saved">{{ __('Saved at :time. It runs on new traffic every minute.', ['time' => $savedAt]) }}</span>
                            @endif
                        </div>

                        @if ($staleVerdictIds !== [] && $this->canManage)
                            <flux:callout class="mt-4" variant="warning" icon="tag" data-test="stale-verdicts">
                                <flux:callout.heading>{{ trans_choice(':count client still holds a verdict this rule no longer matches.|:count clients still hold verdicts this rule no longer matches.', count($staleVerdictIds), ['count' => count($staleVerdictIds)]) }}</flux:callout.heading>
                                <flux:callout.text>{{ __('Changing a rule doesn\'t lift what it already decided. Replayed against the requests that earned them, the new version wouldn\'t have reached these.') }}</flux:callout.text>
                                <x-slot name="actions">
                                    <flux:button size="sm" variant="primary" wire:click="liftStale" data-test="lift-stale">{{ trans_choice('Lift it|Lift them', count($staleVerdictIds)) }}</flux:button>
                                    <flux:button size="sm" variant="ghost" :href="route('tags.index')" wire:navigate>{{ __('Review in Tags') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="keepStale">{{ __('Keep') }}</flux:button>
                                </x-slot>
                            </flux:callout>
                        @endif
                    </x-ntb.panel>

                    {{-- Where its clients go: read from the board's destinations, set in the script above. --}}
                    @if ($editingId)
                        @php($destinations = $this->ruleDestinations)
                        <x-ntb.panel class="mt-6" :heading="__('Where its clients go')" :subheading="__('Set in the script: add GYM \'routine\', TARPIT \'name\' or BLACKHOLE after the duration. The board and the edge read this.')" data-test="rule-destinations">
                            @if ($destinations === [])
                                <p class="text-sm text-zinc-500 dark:text-zinc-400" data-test="rule-destinations-none">{{ __('Nowhere yet — it only tags its clients. Add a destination clause to the script to send them somewhere.') }}</p>
                            @else
                                <ul class="divide-y divide-line rounded-lg border border-line dark:divide-zinc-800 dark:border-zinc-800">
                                    @foreach ($destinations as $destination)
                                        <li wire:key="rule-dest-{{ $loop->index }}" class="flex items-start gap-3 px-3 py-2.5" data-test="rule-destination">
                                            <flux:icon :icon="$destination->icon" variant="mini" class="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                                            <div class="min-w-0 flex-1 text-sm">
                                                @if ($destination->url)
                                                    <a href="{{ $destination->url }}" wire:navigate class="font-medium hover:underline">{{ $destination->label }}</a>
                                                @else
                                                    <span class="font-medium">{{ $destination->label }}</span>
                                                @endif
                                                @if ($destination->note)
                                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ $destination->note }}</span>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>

                                @if (! $this->hasEnforcingDomain)
                                    <flux:callout class="mt-4" variant="warning" icon="shield-exclamation" data-test="rule-destinations-observe-only">
                                        <flux:callout.text>{{ __('None of your domains is in Enforce mode, so no one is diverted or blocked yet. Switch a domain to Enforce on its page.') }}</flux:callout.text>
                                        <x-slot name="actions">
                                            <flux:button size="sm" variant="ghost" :href="route('domains.index')" wire:navigate>{{ __('Domains') }}</flux:button>
                                        </x-slot>
                                    </flux:callout>
                                @endif
                            @endif
                        </x-ntb.panel>
                    @endif

                    {{-- Backtest --}}
                    @if ($this->parsed['script'] !== null && $this->backtest === null)
                        <div wire:key="backtest-pending-{{ md5($source) }}" wire:init="loadBacktest" class="mt-6 flex items-center gap-3 rounded-xl border border-dashed border-line px-5 py-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400" data-test="backtest-pending">
                            <flux:icon.loading class="size-4" />
                            {{ __('Replaying the last 7 days of traffic through this rule…') }}
                        </div>
                    @elseif ($backtest = $this->backtest)
                        <x-ntb.panel class="mt-6" :heading="__('If this rule had been on for the last :days days', ['days' => $backtest->days])" :subheading="__('A dry run against your real traffic. Nothing is banned or tagged until you save and enable the rule.')">
                            <div wire:loading.class="opacity-60" wire:target="source">
                                @if ($backtest->isEmpty())
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400" data-test="backtest-empty">{{ __('It would not have matched anyone. Loosen a condition, or wait for more traffic.') }}</p>
                                @else
                                    <div class="grid gap-4 sm:grid-cols-3" data-test="backtest-summary">
                                        <x-ntb.metric :label="__('Subjects matched')" :value="Number::format($backtest->subjectCount)" icon="user-group" :hint="__('IPs / fingerprints it would act on')" />
                                        <x-ntb.metric :label="__('Their requests')" :value="Number::format($backtest->requestCount)" icon="arrows-right-left" :hint="__('that passed WHERE')" />
                                        <x-ntb.metric :label="__('Outside hosting')" :value="Number::format($backtest->outsideHostingCount)" icon="home" :hint="__('not on a known cloud network')" />
                                    </div>

                                    @if ($backtest->outsideHostingCount > 0)
                                        <flux:callout class="mt-4" variant="warning" icon="exclamation-triangle" data-test="backtest-warning">
                                            <flux:callout.text>
                                                {{ __(':count of these are on home, office, mobile or VPN networks. Shared IPs mean real people may sit behind them: check before banning by IP alone, or add ja4 to the subjects.', ['count' => $backtest->outsideHostingCount]) }}
                                            </flux:callout.text>
                                        </flux:callout>
                                    @endif

                                    <flux:table class="mt-4">
                                        <flux:table.columns>
                                            <flux:table.column>{{ __('Subject') }}</flux:table.column>
                                            <flux:table.column>{{ __('Network') }}</flux:table.column>
                                            <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                                            <flux:table.column align="end" class="max-md:hidden">{{ __('Hosts') }}</flux:table.column>
                                            <flux:table.column class="max-md:hidden">{{ __('Last seen') }}</flux:table.column>
                                        </flux:table.columns>
                                        <flux:table.rows>
                                            @foreach ($backtest->subjects as $subject)
                                                <flux:table.row :key="'backtest-'.$loop->index">
                                                    <flux:table.cell class="font-mono text-xs">
                                                        {{ $subject['client_ip'] ?? '—' }}
                                                        @if ($subject['ja4'])
                                                            <span class="mt-0.5 block text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ $subject['ja4'] }}</span>
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-xs">
                                                        {{ $subject['network'] ?? __('Unknown') }}
                                                        <x-ntb.country :code="$subject['country']" class="ms-1 text-zinc-400" />
                                                        @if ($subject['is_hosting'])
                                                            <flux:badge size="sm" color="amber" class="ms-1">{{ __('Hosting') }}</flux:badge>
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums">{{ Number::format($subject['requests']) }}</flux:table.cell>
                                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ $subject['hosts'] }}</flux:table.cell>
                                                    <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">{{ \Carbon\CarbonImmutable::parse($subject['last_seen'])->diffForHumans(short: true) }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                @endif
                            </div>
                        </x-ntb.panel>
                    @endif
                @endif
    </flux:modal>

    @if ($this->canManage)
        <livewire:pages::clients.label-modal />
    @endif
</div>

