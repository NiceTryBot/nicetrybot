<?php

use App\Actions\Gym\BacktestGymRoutine;
use App\Actions\Gym\SaveGymRoutine;
use App\Actions\Gym\StartGymPreview;
use App\Data\GymEntry;
use App\Data\RuleBacktest;
use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Gym\Contracts\GymAddon;
use App\Gym\Contracts\Obstacle;
use App\Gym\GymAddons;
use App\Gym\GymPipeline;
use App\Gym\GymPlan;
use App\Gym\HashPuzzle;
use App\Gym\ObstacleRegistry;
use App\Gym\RoutineTemplates;
use App\Models\GymRoutine;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Gym routine')] class extends Component {
    public ?GymRoutine $routine = null;

    public string $name = '';

    public bool $enabled = true;

    /**
     * Whether a visitor must match all conditions or any one: "all" or "any".
     */
    public string $match = 'all';

    /**
     * @var list<array{field: string, operator: string, values: list<string>}>
     */
    public array $conditions = [];

    /**
     * @var list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>
     */
    public array $obstacles = [];

    public int $exitAction = 1;

    public int $passSeconds = 3600;

    public bool $oneTry = false;

    /**
     * Each gym add-on's setting for the routine, by add-on key. Packages keep these themselves.
     *
     * @var array<string, mixed>
     */
    public array $addons = [];

    /**
     * The obstacle open for settings and shown in the live preview.
     */
    public ?string $selected = null;

    public string $device = 'desktop';

    /**
     * What's being typed into each typed condition, by row.
     *
     * @var array<int, string>
     */
    public array $drafts = [];

    public string $savedSnapshot = '';

    /**
     * Who enters (as entryKey names it) when the backtest was last asked for.
     */
    #[Locked]
    public ?string $backtestedEntry = null;

    #[Url]
    public ?string $template = null;

    public function mount(?GymRoutine $routine = null): void
    {
        if ($routine !== null) {
            abort_unless($routine->team_id === $this->team->id, 404);

            $this->routine = $routine;
            $this->fillFrom($routine->name, $routine->enabled, $routine->match_all, $routine->conditions, $routine->obstacles, $routine->exit_action, $routine->pass_seconds);
            $this->oneTry = (bool) $routine->one_try;
            $this->loadAddons($routine);
            $this->savedSnapshot = $this->snapshot();

            return;
        }

        Gate::authorize('create', [GymRoutine::class, $this->team]);

        $rule = request()->integer('rule') > 0 ? $this->team->edgeRules()->find(request()->integer('rule')) : null;
        $starter = $rule !== null ? RoutineTemplates::forRule($rule) : RoutineTemplates::attributes($this->template);

        $this->fillFrom(
            $starter['name'] ?? '',
            true,
            $starter['match_all'] ?? true,
            $starter['conditions'] ?? [],
            $starter['obstacles'] ?? [],
            $starter['exit_action'] ?? GymExit::Continue,
            $starter['pass_seconds'] ?? GymPipeline::DEFAULT_PASS_SECONDS,
        );
        $this->loadAddons(null);
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->routine === null
            ? Gate::allows('create', [GymRoutine::class, $this->team])
            : Gate::allows('update', $this->routine);
    }

    #[Computed]
    public function registry(): ObstacleRegistry
    {
        return app(ObstacleRegistry::class);
    }

    #[Computed]
    public function entry(): GymEntry
    {
        return GymEntry::fromArray($this->conditions, $this->match !== 'any');
    }

    /**
     * Name who enters, as the conditions stand, for the backtest's cache and placeholder.
     */
    #[Computed]
    public function entryKey(): string
    {
        return sha1((string) json_encode([$this->conditions, $this->match !== 'any']));
    }

    /**
     * Who the entry would have let in over the last week, once it's been asked for (or it's still cached
     * from a few minutes ago). The editor opens without it and its placeholder asks for it, so a slow
     * backtest never holds up opening or editing the routine. It only depends on the entry, so it's kept
     * for a few minutes rather than run again on every click elsewhere in the editor (as plain data: the
     * cache doesn't unserialize objects).
     */
    #[Computed]
    public function backtest(): ?RuleBacktest
    {
        $key = 'gym.backtest.'.$this->team->id.'.'.$this->entryKey;

        if ($this->backtestedEntry !== $this->entryKey && ! Cache::has($key)) {
            return null;
        }

        $result = Cache::remember($key, now()->addMinutes(5), fn () => (array) app(BacktestGymRoutine::class)->handle($this->team, $this->entry));

        return new RuleBacktest(...$result);
    }

    /**
     * Run the backtest for who enters as the conditions stand.
     */
    public function loadBacktest(): void
    {
        $this->backtestedEntry = $this->entryKey;
    }

    #[Computed]
    public function plan(): GymPlan
    {
        return app(GymPipeline::class)->planFor([$this->blueprint()]);
    }

    /**
     * Get the other enabled routines a visitor could also match, which stack with this one.
     *
     * @return Collection<int, GymRoutine>
     */
    #[Computed]
    public function stackedWith(): Collection
    {
        return $this->team->gymRoutines()->where('enabled', true)->when($this->routine, fn ($query) => $query->whereKeyNot($this->routine->id))->get();
    }

    #[Computed]
    public function isDirty(): bool
    {
        return $this->snapshot() !== $this->savedSnapshot;
    }

    /**
     * Get the signed URL that draws the selected obstacle, as configured right now, for the preview.
     */
    #[Computed]
    public function previewUrl(): ?string
    {
        $entry = collect($this->obstacles)->firstWhere('id', $this->selected);
        $obstacle = $entry === null ? null : $this->registry->find($entry['type']);

        if ($obstacle === null) {
            return null;
        }

        return url()->signedRoute('gym.demo', [
            'obstacle' => $obstacle->key(),
            'config' => rtrim(strtr(base64_encode((string) json_encode($obstacle->configure($entry['config']))), '+/', '-_'), '='),
        ]);
    }

    public function addCondition(string $field): void
    {
        $field = GymField::from($field);

        $this->conditions[] = ['field' => $field->value, 'operator' => $field->operators()[0]->value, 'values' => []];
    }

    public function changeField(int $index, string $field): void
    {
        $field = GymField::from($field);

        $this->conditions[$index] = ['field' => $field->value, 'operator' => $field->operators()[0]->value, 'values' => []];
        $this->drafts[$index] = '';
        $this->resetErrorBag("conditions.{$index}");
    }

    public function removeCondition(int $index): void
    {
        array_splice($this->conditions, $index, 1);
        $this->drafts = [];
        $this->resetErrorBag();
    }

    public function addValue(int $index, string $value): void
    {
        $field = GymField::from($this->conditions[$index]['field']);
        $added = [];

        foreach ($field->isPicked() ? [$value] : preg_split('/[\n,]+/', $value) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            $normalized = $field->normalize($candidate);

            if ($normalized === null || ($field->isPicked() && ! array_key_exists($normalized, $field->options($this->team)))) {
                $this->addError("conditions.{$index}", $field->invalidValueMessage());

                return;
            }

            $added[] = $normalized;
        }

        $this->conditions[$index]['values'] = array_values(array_unique([...$this->conditions[$index]['values'], ...$added]));
        $this->drafts[$index] = '';
        $this->resetErrorBag("conditions.{$index}");
    }

    public function addDraft(int $index): void
    {
        $this->addValue($index, $this->drafts[$index] ?? '');
    }

    public function removeValue(int $index, int $valueIndex): void
    {
        array_splice($this->conditions[$index]['values'], $valueIndex, 1);
    }

    public function addObstacle(string $type): void
    {
        $obstacle = $this->registry->find($type);
        abort_if($obstacle === null, 404);

        $existing = collect($this->obstacles)->firstWhere('type', $type);

        if ($existing === null) {
            $existing = ['id' => $this->newObstacleId(), 'type' => $type, 'config' => $obstacle->configure([]), 'escalate' => null];
            $this->obstacles[] = $existing;
        }

        $this->selected = $existing['id'];

        Flux::modal('obstacle-library')->close();
    }

    public function removeObstacle(string $id): void
    {
        $this->obstacles = array_values(array_filter($this->obstacles, fn (array $obstacle) => $obstacle['id'] !== $id));

        if ($this->selected === $id) {
            $this->selected = $this->obstacles[0]['id'] ?? null;
        }
    }

    public function sortObstacle(string $id, int $position): void
    {
        $index = collect($this->obstacles)->search(fn (array $obstacle) => $obstacle['id'] === $id);

        if ($index === false) {
            return;
        }

        $moved = array_splice($this->obstacles, $index, 1);
        array_splice($this->obstacles, max(0, min($position, count($this->obstacles))), 0, $moved);
    }

    public function select(string $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
    }

    public function save(SaveGymRoutine $saveGymRoutine): void
    {
        $this->routine === null
            ? Gate::authorize('create', [GymRoutine::class, $this->team])
            : Gate::authorize('update', $this->routine);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'exitAction' => ['required', Rule::enum(GymExit::class)],
            'passSeconds' => ['required', 'integer', Rule::in(array_keys(GymRoutine::PASS_DURATIONS))],
            'oneTry' => ['boolean'],
        ], [
            'name.required' => __('Give the routine a name.'),
        ]);

        foreach ($this->conditions as $index => $condition) {
            if ($condition['values'] === []) {
                $this->addError("conditions.{$index}", __('Pick at least one value, or remove this condition.'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $routine = $saveGymRoutine->handle($this->team, $this->routine, [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'match_all' => $this->match !== 'any',
            'conditions' => $this->conditions,
            'obstacles' => $this->obstacles,
            'exit_action' => GymExit::from($this->exitAction),
            'pass_seconds' => $this->passSeconds,
            'one_try' => $this->oneTry,
        ], $this->addons);

        if ($this->routine === null) {
            Flux::toast(variant: 'success', text: __('Routine created.'));

            $this->redirectRoute('gym.routines.edit', ['routine' => $routine], navigate: true);

            return;
        }

        $this->fillFrom($this->routine->name, $this->routine->enabled, $this->routine->match_all, $this->routine->conditions, $this->routine->obstacles, $this->routine->exit_action, $this->routine->pass_seconds);
        $this->oneTry = (bool) $this->routine->one_try;
        $this->loadAddons($this->routine);
        $this->savedSnapshot = $this->snapshot();

        Flux::toast(variant: 'success', text: __('Saved. Visitors already in the gym get the new pipeline at their next obstacle.'));
    }

    /**
     * Start a preview of the routine as it stands in the editor, saved or not, and return its URL.
     */
    public function tryIt(StartGymPreview $startGymPreview): string
    {
        Gate::authorize('viewAny', [GymRoutine::class, $this->team]);

        return route('gym.show', $startGymPreview->handle($this->team, [$this->blueprint()]));
    }

    public function delete(): void
    {
        abort_if($this->routine === null, 404);
        Gate::authorize('delete', $this->routine);

        $this->routine->delete();

        Flux::toast(text: __('Routine deleted.'));

        $this->redirectRoute('gym.index', navigate: true);
    }

    /**
     * Get the routine as the pipeline builds it.
     *
     * @return array{id: int|null, name: string, obstacles: list<array<string, mixed>>, exit: int, pass_seconds: int}
     */
    private function blueprint(): array
    {
        return [
            'id' => $this->routine?->id,
            'name' => $this->name !== '' ? $this->name : __('Untitled routine'),
            'obstacles' => $this->normalizedObstacles(),
            'exit' => $this->exitAction,
            'pass_seconds' => $this->passSeconds,
        ];
    }

    /**
     * Get the obstacles with each config brought within its settings' bounds. Entries whose obstacle
     * is no longer registered are kept as they are, so saving never loses them.
     *
     * @return list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>
     */
    private function normalizedObstacles(): array
    {
        return app(SaveGymRoutine::class)->normalizeObstacles($this->obstacles);
    }

    /**
     * @param  list<array{field: string, operator: string, values: list<string>}>  $conditions
     * @param  list<array<string, mixed>>  $obstacles
     */
    private function fillFrom(string $name, bool $enabled, bool $matchAll, array $conditions, array $obstacles, GymExit $exit, int $passSeconds): void
    {
        $this->name = $name;
        $this->enabled = $enabled;
        $this->match = $matchAll ? 'all' : 'any';
        $this->conditions = array_values($conditions);
        $this->obstacles = array_map(fn (array $entry) => [
            'id' => (string) $entry['id'],
            'type' => (string) $entry['type'],
            'config' => $this->registry->find((string) $entry['type'])?->configure((array) ($entry['config'] ?? [])) ?? (array) ($entry['config'] ?? []),
            'escalate' => $entry['escalate'] ?? null,
        ], array_values($obstacles));
        $this->exitAction = $exit->value;
        $this->passSeconds = $passSeconds;
        $this->selected ??= $this->obstacles[0]['id'] ?? null;
    }

    private function snapshot(): string
    {
        return (string) json_encode([$this->name, $this->enabled, $this->match, $this->conditions, $this->normalizedObstacles(), $this->exitAction, $this->passSeconds, $this->oneTry, $this->addons]);
    }

    /**
     * Fill each gym add-on's setting for the routine, or its default for a new one.
     */
    private function loadAddons(?GymRoutine $routine): void
    {
        $this->addons = collect(app(GymAddons::class)->all())->mapWithKeys(fn (GymAddon $addon) => [$addon->key() => $addon->load($routine)])->all();
    }

    private function newObstacleId(): string
    {
        return Str::lower(Str::random(10));
    }
}; ?>

@php
    $registry = $this->registry;
    $plan = $this->plan;
    $entry = $this->entry;
@endphp

<div>
    {{-- Heading --}}
    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0 flex-1">
            <a href="{{ route('gym.index') }}" wire:navigate class="mb-2 inline-flex items-center gap-1 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase hover:text-coral-600 dark:text-zinc-400">
                <flux:icon.arrow-left variant="micro" class="size-3" />{{ __('Bot gym') }}
            </a>
            <div class="flex items-baseline gap-1">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="name"
                    value="{{ $name }}"
                    placeholder="{{ __('Name this routine') }}"
                    aria-label="{{ __('Routine name') }}"
                    @disabled(! $this->canManage)
                    class="min-w-0 flex-1 bg-transparent text-2xl font-bold sm:text-3xl tracking-tight text-ink outline-none placeholder:text-zinc-300 dark:text-white dark:placeholder:text-zinc-700"
                    data-test="routine-name"
                />
            </div>
            @error('name') <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Who enters, what they go through, and where they come out. Changes reach visitors already in the gym at their next obstacle.') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($this->isDirty)
                <span class="inline-flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300" data-test="routine-dirty">
                    <span class="size-1.5 rounded-full bg-amber-500"></span>{{ $routine ? __('Unsaved changes') : __('Not saved yet') }}
                </span>
            @endif
            <flux:switch wire:model.live="enabled" :label="__('Enabled')" :disabled="! $this->canManage" />
            <flux:button
                size="sm"
                icon="play"
                x-data
                x-on:click="const tab = window.open('', '_blank'); $wire.tryIt().then(url => { tab ? tab.location = url : window.location.assign(url) })"
                data-test="try-routine"
            >{{ __('Try it') }}</flux:button>
            @if ($this->canManage)
                <flux:button variant="primary" size="sm" wire:click="save" data-test="save-routine">{{ $routine ? __('Save routine') : __('Create routine') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_25rem]">
        {{-- The track: who enters → obstacles → exit --}}
        <ol class="min-w-0">
            {{-- 1 · Who enters --}}
            <li class="relative pb-8 ps-10 sm:ps-12">
                <span class="absolute start-[0.9rem] top-9 bottom-0 w-px bg-line dark:bg-zinc-800" aria-hidden="true"></span>
                <span class="absolute start-0 top-0 flex size-8 items-center justify-center rounded-full bg-ink font-mono text-xs font-bold text-white dark:bg-zinc-100 dark:text-ink">1</span>

                <x-ntb.panel :heading="__('Who enters')" :subheading="__('Pick the visitors this routine is for. Leave it empty and everyone enters.')">
                    @if (count($conditions) > 1)
                        <x-slot:actions>
                            <flux:radio.group wire:model.live="match" variant="segmented" size="sm" :aria-label="__('Match all or any')">
                                <flux:radio value="all" :label="__('Match all')" />
                                <flux:radio value="any" :label="__('Match any')" />
                            </flux:radio.group>
                        </x-slot:actions>
                    @endif

                    <div class="space-y-2" data-test="conditions">
                        @foreach ($conditions as $index => $condition)
                            @php
                                $field = GymField::from($condition['field']);
                                $options = $field->isPicked() ? $field->options($this->team) : [];
                            @endphp

                            @if ($index > 0)
                                <div class="ps-3 font-mono text-[0.65rem] font-semibold tracking-widest text-coral-600 uppercase dark:text-coral-400">{{ $match === 'any' ? __('or') : __('and') }}</div>
                            @endif

                            <div wire:key="condition-{{ $index }}-{{ $condition['field'] }}" @class([
                                'rounded-lg border bg-canvas/60 p-3 dark:bg-zinc-950/40',
                                'border-rose-400 dark:border-rose-500/60' => $errors->has("conditions.{$index}"),
                                'border-line dark:border-zinc-800' => ! $errors->has("conditions.{$index}"),
                            ]) data-test="condition-row">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:icon :icon="$field->icon()" variant="mini" class="size-4 text-zinc-400" />
                                    <select
                                        wire:change="changeField({{ $index }}, $event.target.value)"
                                        @disabled(! $this->canManage)
                                        aria-label="{{ __('Field') }}"
                                        class="rounded-md border border-line bg-white py-1 ps-2 pe-7 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900"
                                    >
                                        @foreach (GymField::cases() as $option)
                                            <option value="{{ $option->value }}" @selected($option === $field)>{{ $option->label() }}</option>
                                        @endforeach
                                    </select>
                                    <select
                                        wire:model.live="conditions.{{ $index }}.operator"
                                        @disabled(! $this->canManage)
                                        aria-label="{{ __('Comparison') }}"
                                        class="rounded-md border border-line bg-white py-1 ps-2 pe-7 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                    >
                                        @foreach ($field->operators() as $operator)
                                            <option value="{{ $operator->value }}">{{ $field->operatorLabel($operator) }}</option>
                                        @endforeach
                                    </select>

                                    @if ($this->canManage)
                                        <flux:button size="xs" variant="subtle" icon="x-mark" class="ms-auto" wire:click="removeCondition({{ $index }})" :aria-label="__('Remove condition')" />
                                    @endif
                                </div>

                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    @foreach ($condition['values'] as $valueIndex => $value)
                                        <span wire:key="value-{{ $index }}-{{ $value }}" class="inline-flex items-center gap-1 rounded-md border border-line bg-white py-0.5 ps-2 pe-1 text-xs dark:border-zinc-700 dark:bg-zinc-900">
                                            @if ($field === GymField::Country)
                                                <x-ntb.country :code="$value" :show-code="false" />
                                            @endif
                                            @if ($field === GymField::Verdict && $value !== GymField::ANY_RULE)
                                                <a href="{{ route('rules.index', ['rule' => $value]) }}" target="_blank" class="group inline-flex items-center gap-0.5 hover:text-coral-600 hover:underline dark:hover:text-coral-400" title="{{ __('Open this rule in a new tab') }}" data-test="condition-rule-link">
                                                    {{ $options[$value] ?? $value }}
                                                    <flux:icon.arrow-top-right-on-square variant="micro" class="size-3 text-zinc-400 group-hover:text-coral-500" />
                                                </a>
                                            @else
                                                <span @class(['font-mono' => ! $field->isPicked()])>{{ $options[$value] ?? $value }}</span>
                                            @endif
                                            @if ($this->canManage)
                                                <button type="button" wire:click="removeValue({{ $index }}, {{ $valueIndex }})" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800" aria-label="{{ __('Remove :value', ['value' => $options[$value] ?? $value]) }}">
                                                    <flux:icon.x-mark variant="micro" class="size-3" />
                                                </button>
                                            @endif
                                        </span>
                                    @endforeach

                                    @if ($this->canManage)
                                        @if ($field->isPicked())
                                            <select
                                                x-on:change="$wire.addValue({{ $index }}, $event.target.value); $event.target.value = ''"
                                                aria-label="{{ __('Add a value') }}"
                                                class="rounded-md border border-dashed border-zinc-300 bg-transparent py-0.5 ps-2 pe-7 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                                                data-test="condition-add-value"
                                            >
                                                <option value="">{{ $condition['values'] === [] ? __('Choose…') : __('Add…') }}</option>
                                                @foreach (array_diff_key($options, array_flip($condition['values'])) as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input
                                                type="text"
                                                wire:model="drafts.{{ $index }}"
                                                wire:keydown.enter.prevent="addDraft({{ $index }})"
                                                placeholder="{{ $field->placeholder() }}"
                                                aria-label="{{ __('Add a value') }}"
                                                class="min-w-48 flex-1 rounded-md border border-dashed border-zinc-300 bg-transparent px-2 py-0.5 font-mono text-xs outline-none focus:border-coral-400 dark:border-zinc-700"
                                                data-test="condition-draft"
                                            />
                                            <flux:button size="xs" variant="ghost" wire:click="addDraft({{ $index }})">{{ __('Add') }}</flux:button>
                                        @endif
                                    @endif
                                </div>

                                @error("conditions.{$index}") <p class="mt-1.5 text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>

                    @if ($this->canManage)
                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="sm" variant="ghost" icon="plus" class="mt-3" data-test="add-condition">{{ __('Add condition') }}</flux:button>

                            <flux:menu class="max-h-96 overflow-y-auto">
                                @foreach (collect(GymField::cases())->groupBy(fn (GymField $field) => $field->group()) as $group => $fields)
                                    <flux:menu.group :heading="$group">
                                        @foreach ($fields as $field)
                                            <flux:menu.item :icon="$field->icon()" wire:click="addCondition('{{ $field->value }}')">{{ $field->label() }}</flux:menu.item>
                                        @endforeach
                                    </flux:menu.group>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif

                    {{-- Reads as --}}
                    <div class="mt-4 flex gap-2 border-t border-line pt-4 text-sm dark:border-zinc-800" wire:loading.class="opacity-60" data-test="entry-reads-as">
                        <flux:icon.chat-bubble-left-ellipsis variant="mini" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <p><span class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Reads as') }}</span> {{ $entry->describe($this->team) }}</p>
                    </div>

                    @if ($entry->isEveryone())
                        <flux:callout class="mt-3" variant="warning" icon="exclamation-triangle">
                            <flux:callout.text>{{ __('No conditions: every visitor to your domains enters this routine, people included. Add a condition, or keep the obstacles light.') }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    {{-- Backtest --}}
                    @php
                        $backtest = $this->backtest;
                    @endphp
                    @if ($backtest === null)
                        <div wire:key="backtest-pending-{{ $this->entryKey }}" wire:init="loadBacktest" class="mt-4 flex items-center gap-3 rounded-lg bg-canvas p-4 text-sm text-zinc-500 dark:bg-zinc-950/50 dark:text-zinc-400" data-test="routine-backtest-pending">
                            <flux:icon.loading class="size-4" />
                            {{ __('Replaying the last 7 days of traffic through who enters…') }}
                        </div>
                    @else
                        <div class="mt-4 rounded-lg bg-canvas p-4 dark:bg-zinc-950/50" wire:loading.class="opacity-60" data-test="routine-backtest">
                            @if ($backtest->isEmpty())
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('In the last :days days, nobody would have entered. Loosen a condition, or wait for more traffic.', ['days' => $backtest->days]) }}</p>
                            @else
                                <p class="text-sm">
                                    {!! __('In the last :days days, this would have sent :visitors to the gym, with :requests between them.', [
                                        'days' => $backtest->days,
                                        'visitors' => '<strong class="font-semibold">'.e(trans_choice(':count visitor|:count visitors', $backtest->subjectCount, ['count' => Number::format($backtest->subjectCount)])).'</strong>',
                                        'requests' => e(trans_choice(':count request|:count requests', $backtest->requestCount, ['count' => Number::format($backtest->requestCount)])),
                                    ]) !!}
                                </p>
                                @if ($backtest->outsideHostingCount > 0)
                                    <p class="mt-2 flex gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                                        {{ trans_choice(':count of them is on a home, office or mobile network, where people sit. Keep the obstacles fair for them.|:count of them are on home, office or mobile networks, where people sit. Keep the obstacles fair for them.', $backtest->outsideHostingCount, ['count' => Number::format($backtest->outsideHostingCount)]) }}
                                    </p>
                                @endif
                                <details class="mt-3 text-xs">
                                    <summary class="cursor-pointer text-zinc-500 select-none dark:text-zinc-400">{{ __('Who they are') }}</summary>
                                    <ul class="mt-2 divide-y divide-line dark:divide-zinc-800">
                                        @foreach ($backtest->subjects as $subject)
                                            <li class="flex items-center gap-3 py-1.5" wire:key="backtest-{{ $loop->index }}">
                                                <span class="w-32 shrink-0 truncate font-mono">{{ $subject['client_ip'] }}</span>
                                                <span class="min-w-0 flex-1 truncate text-zinc-500 dark:text-zinc-400">
                                                    {{ $subject['network'] ?? __('Unknown network') }}
                                                    <x-ntb.country :code="$subject['country']" class="ms-1" />
                                                </span>
                                                @if ($subject['is_hosting'])
                                                    <flux:badge size="sm" color="amber">{{ __('Hosting') }}</flux:badge>
                                                @endif
                                                <span class="w-14 text-end font-mono tabular-nums">{{ Number::abbreviate($subject['requests']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </div>
                    @endif
                </x-ntb.panel>
            </li>

            {{-- 2 · Obstacles --}}
            <li class="relative pb-8 ps-10 sm:ps-12">
                <span class="absolute start-[0.9rem] top-9 bottom-0 w-px bg-line dark:bg-zinc-800" aria-hidden="true"></span>
                <span class="absolute start-0 top-0 flex size-8 items-center justify-center rounded-full bg-ink font-mono text-xs font-bold text-white dark:bg-zinc-100 dark:text-ink">2</span>

                <x-ntb.panel :heading="__('Obstacles')" :subheading="__('Visitors clear these in order, like handlers in a pipeline. Drag to reorder; click one to tune it.')">
                    @if ($obstacles === [])
                        <div class="rounded-lg border border-dashed border-line p-6 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                            {{ __('No obstacles yet. Visitors who enter go straight to the exit.') }}
                        </div>
                    @endif

                    <div wire:sort="sortObstacle" class="space-y-0" data-test="obstacles">
                        @foreach ($obstacles as $index => $item)
                            @php
                                $definition = $registry->find($item['type']);
                                $isSelected = $selected === $item['id'];
                            @endphp

                            <div wire:key="obstacle-{{ $item['id'] }}" wire:sort:item="{{ $item['id'] }}" class="group">
                                @if ($index > 0)
                                    <div class="flex justify-center py-1 text-zinc-300 dark:text-zinc-700" aria-hidden="true">
                                        <flux:icon.arrow-down variant="micro" class="size-4" />
                                    </div>
                                @endif

                                <div @class([
                                    'rounded-xl border bg-white transition dark:bg-zinc-900',
                                    'border-coral-400 ring-1 ring-coral-400/40' => $isSelected,
                                    'border-line hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700' => ! $isSelected,
                                ]) data-test="obstacle-card">
                                    <div class="flex items-center gap-3 p-3">
                                        @if ($this->canManage)
                                            <span wire:sort:handle class="cursor-grab text-zinc-300 hover:text-zinc-500 active:cursor-grabbing dark:text-zinc-600" title="{{ __('Drag to reorder') }}">
                                                <flux:icon.bars-2 variant="mini" class="size-4" />
                                            </span>
                                        @endif
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-coral-50 text-coral-600 dark:bg-coral-500/10 dark:text-coral-300">
                                            <flux:icon :icon="$definition?->icon() ?? 'question-mark-circle'" variant="mini" class="size-5" />
                                        </span>
                                        <button type="button" wire:click="select('{{ $item['id'] }}')" class="min-w-0 flex-1 text-start" wire:sort:ignore>
                                            <span class="block truncate font-semibold">
                                                <span class="me-1 font-mono text-xs text-zinc-400">{{ $index + 1 }}</span>{{ $definition?->name() ?? __('Unknown obstacle “:type”', ['type' => $item['type']]) }}
                                            </span>
                                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $definition?->summarize($definition->configure($item['config'])) ?? __('Not installed any more, so visitors skip it.') }}
                                            </span>
                                        </button>
                                        @if (filled($item['escalate'] ?? null) && $registry->find($item['escalate']))
                                            <flux:tooltip :content="__('If it looks automated: add :obstacle', ['obstacle' => $registry->find($item['escalate'])->name()])">
                                                <flux:badge size="sm" color="amber" icon="arrow-trending-up" class="max-sm:hidden">{{ $registry->find($item['escalate'])->name() }}</flux:badge>
                                            </flux:tooltip>
                                        @endif
                                        <flux:icon :icon="$isSelected ? 'chevron-up' : 'chevron-down'" variant="micro" class="size-4 text-zinc-400" />
                                    </div>

                                    @if ($isSelected && $definition)
                                        <div class="border-t border-line p-4 dark:border-zinc-800" wire:sort:ignore data-test="obstacle-settings">
                                            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $definition->tagline() }}</p>

                                            <div class="grid grid-cols-[minmax(0,1fr)] gap-x-6 gap-y-5 sm:grid-cols-2">
                                                @foreach ($definition->settings() as $setting)
                                                    @php
                                                        $value = $item['config'][$setting->key] ?? $setting->default;
                                                        $model = "obstacles.{$index}.config.{$setting->key}";
                                                    @endphp

                                                    <div wire:key="setting-{{ $item['id'] }}-{{ $setting->key }}" @class(['sm:col-span-2' => $setting->type === 'text'])>
                                                        @switch($setting->type)
                                                            @case('range')
                                                                <div x-data="{ value: {{ (int) $value }} }">
                                                                    <div class="flex items-baseline justify-between gap-2">
                                                                        <label class="text-sm font-medium" for="{{ $model }}">{{ $setting->label }}</label>
                                                                        <span class="font-mono text-xs text-zinc-600 tabular-nums dark:text-zinc-300"><span x-text="value">{{ (int) $value }}</span> {{ $setting->unit }}</span>
                                                                    </div>
                                                                    <input
                                                                        id="{{ $model }}"
                                                                        type="range"
                                                                        min="{{ $setting->min }}"
                                                                        max="{{ $setting->max }}"
                                                                        step="{{ $setting->step }}"
                                                                        wire:model.live.change="{{ $model }}"
                                                                        x-on:input="value = $event.target.value"
                                                                        @disabled(! $this->canManage)
                                                                        class="mt-2 w-full accent-coral-500"
                                                                    />
                                                                    <div class="flex justify-between font-mono text-[0.65rem] text-zinc-400"><span>{{ $setting->min }}</span><span>{{ $setting->max }}</span></div>
                                                                </div>
                                                                @break
                                                            @case('choice')
                                                                @if (count($setting->options) <= 3 && max(array_map('mb_strlen', $setting->options)) <= 14)
                                                                    <p class="mb-2 text-sm font-medium">{{ $setting->label }}</p>
                                                                    <flux:radio.group wire:model.live="{{ $model }}" variant="segmented" size="sm" :disabled="! $this->canManage">
                                                                        @foreach ($setting->options as $optionValue => $optionLabel)
                                                                            <flux:radio :value="$optionValue" :label="$optionLabel" />
                                                                        @endforeach
                                                                    </flux:radio.group>
                                                                @else
                                                                    <flux:select wire:model.live="{{ $model }}" :label="$setting->label" size="sm" :disabled="! $this->canManage">
                                                                        @foreach ($setting->options as $optionValue => $optionLabel)
                                                                            <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                                                                        @endforeach
                                                                    </flux:select>
                                                                @endif
                                                                @break
                                                            @case('toggle')
                                                                <flux:switch wire:model.live="{{ $model }}" :label="$setting->label" :disabled="! $this->canManage" class="mt-6" />
                                                                @break
                                                            @case('text')
                                                                <flux:input wire:model.live.debounce.500ms="{{ $model }}" :label="$setting->label" size="sm" :maxlength="$setting->max" :disabled="! $this->canManage" />
                                                                @break
                                                        @endswitch

                                                        @if ($setting->help)
                                                            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $setting->help }}</p>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>

                                            @if ($definition->flagsWhen())
                                                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50/70 p-3 dark:border-amber-500/20 dark:bg-amber-500/5" data-test="obstacle-escalation">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <flux:icon.arrow-trending-up variant="mini" class="size-4 text-amber-600 dark:text-amber-400" />
                                                        <label for="escalate-{{ $item['id'] }}" class="text-sm font-medium">{{ __('If it looks automated') }}</label>
                                                        <select
                                                            id="escalate-{{ $item['id'] }}"
                                                            wire:model.live="obstacles.{{ $index }}.escalate"
                                                            @disabled(! $this->canManage)
                                                            class="w-full rounded-md border border-line bg-white py-1 ps-2 pe-7 text-sm sm:ms-auto sm:w-auto dark:border-zinc-700 dark:bg-zinc-900"
                                                        >
                                                            <option value="">{{ __('carry on as normal') }}</option>
                                                            @foreach ($registry->all() as $candidate)
                                                                @continue($candidate->key() === $definition->key())
                                                                <option value="{{ $candidate->key() }}" @disabled(collect($obstacles)->contains('type', $candidate->key()))>
                                                                    {{ __('add :obstacle', ['obstacle' => $candidate->name()]) }}{{ collect($obstacles)->contains('type', $candidate->key()) ? ' '.__('(already here)') : '' }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <p class="mt-1.5 text-xs text-amber-900/70 dark:text-amber-200/70">{{ __('Looks automated when:') }} {{ lcfirst($definition->flagsWhen()) }}</p>
                                                </div>
                                            @endif

                                            @if ($this->canManage)
                                                <div class="mt-4 flex justify-end">
                                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeObstacle('{{ $item['id'] }}')" data-test="remove-obstacle">{{ __('Remove') }}</flux:button>
                                                </div>
                                            @endif
                                        </div>
                                    @elseif ($isSelected && $this->canManage)
                                        <div class="flex justify-end border-t border-line p-3 dark:border-zinc-800">
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeObstacle('{{ $item['id'] }}')">{{ __('Remove') }}</flux:button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->canManage)
                        <flux:modal.trigger name="obstacle-library">
                            <flux:button size="sm" variant="ghost" icon="plus" class="mt-3" data-test="add-obstacle">{{ __('Add obstacle') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </x-ntb.panel>
            </li>

            {{-- 3 · Exit --}}
            <li class="relative ps-10 sm:ps-12">
                <span class="absolute start-0 top-0 flex size-8 items-center justify-center rounded-full bg-ink font-mono text-xs font-bold text-white dark:bg-zinc-100 dark:text-ink">3</span>

                <x-ntb.panel :heading="__('Exit')" :subheading="__('Where visitors go once they’ve cleared every obstacle. When routines stack, the strictest exit wins.')">
                    <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('Exit') }}">
                        @foreach (GymExit::cases() as $exit)
                            <label @class([
                                'flex cursor-pointer flex-col rounded-xl border p-3 transition',
                                'border-coral-400 bg-coral-50/50 ring-1 ring-coral-400/40 dark:bg-coral-500/5' => $exitAction === $exit->value,
                                'border-line hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700' => $exitAction !== $exit->value,
                                'pointer-events-none opacity-60' => ! $this->canManage,
                            ]) wire:key="exit-{{ $exit->value }}">
                                <input type="radio" class="sr-only" wire:model.live="exitAction" value="{{ $exit->value }}" />
                                <span class="flex items-center gap-2 text-sm font-semibold">
                                    <flux:icon :icon="$exit->icon()" variant="mini" @class(['size-4', 'text-emerald-600' => $exit === GymExit::Continue, 'text-amber-600' => $exit === GymExit::NotFound, 'text-rose-600' => $exit === GymExit::Block]) />
                                    {{ $exit->label() }}
                                </span>
                                <span class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $exit->description() }}</span>
                            </label>
                        @endforeach
                    </div>

                    @if ($exitAction === GymExit::Continue->value)
                        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                            <label for="pass-seconds">{{ __('Cleared visitors skip the gym for') }}</label>
                            <select id="pass-seconds" wire:model.live="passSeconds" @disabled(! $this->canManage) class="rounded-md border border-line bg-white py-1 ps-2 pe-7 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                @foreach (GymRoutine::PASS_DURATIONS as $seconds => $label)
                                    <option value="{{ $seconds }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <label for="one-try">{{ __('Each pass allows') }}</label>
                            <select id="one-try" wire:model.live="oneTry" @disabled(! $this->canManage) class="rounded-md border border-line bg-white py-1 ps-2 pe-7 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="one-try">
                                <option value="0">{{ __('any pages until it expires') }}</option>
                                <option value="1">{{ __('one form submission') }}</option>
                            </select>
                        </div>
                        @if ($oneTry)
                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('After one submission (a login attempt, say), the visitor trains again before the next. A page that would lift their verdict, like the dashboard after signing in, still opens, and lifts it as soon as it answers.') }}</p>
                        @endif
                    @endif
                </x-ntb.panel>

                {{-- What packages add to routines (App\Gym\GymAddons), each drawing its own setting. --}}
                @foreach (app(GymAddons::class)->all() as $addon)
                    <div class="mt-6" wire:key="addon-{{ $addon->key() }}">
                        @include($addon->editorView(), ['value' => $addons[$addon->key()] ?? null, 'model' => 'addons.'.$addon->key(), 'canManage' => $this->canManage])
                    </div>
                @endforeach

                @if ($routine && $this->canManage)
                    <div class="mt-6 flex justify-end">
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete" wire:confirm="{{ __('Delete this routine? Visitors in the gym right now skip its obstacles from their next step.') }}" data-test="delete-routine">{{ __('Delete routine') }}</flux:button>
                    </div>
                @endif
            </li>
        </ol>

        {{-- Live preview and the workout --}}
        <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
            <x-ntb.panel :heading="__('Live preview')" :padded="false">
                <x-slot:actions>
                    <flux:radio.group wire:model.live="device" variant="segmented" size="sm" :aria-label="__('Preview size')">
                        <flux:radio value="desktop" icon="computer-desktop" :aria-label="__('Desktop')" />
                        <flux:radio value="phone" icon="device-phone-mobile" :aria-label="__('Phone')" />
                    </flux:radio.group>
                </x-slot:actions>

                <div class="border-t border-line bg-canvas p-3 dark:border-zinc-800 dark:bg-zinc-950" data-test="routine-preview">
                    @if ($this->previewUrl)
                        {{-- Drawn at 125% and scaled to 80%: a true miniature, so a phone frame is a real 375px viewport. --}}
                        <div @class([
                            'relative mx-auto h-[34rem] overflow-hidden rounded-lg border border-line bg-white dark:border-zinc-800',
                            'w-full' => $device === 'desktop',
                            'w-[18.75rem] max-w-full' => $device === 'phone',
                        ])>
                            <iframe
                                wire:key="preview-{{ md5($this->previewUrl.$device) }}"
                                src="{{ $this->previewUrl }}"
                                title="{{ __('Preview of the selected obstacle') }}"
                                class="absolute start-0 top-0 h-[125%] w-[125%] origin-top-left scale-[0.8]"
                            ></iframe>
                        </div>
                        @php
                            $previewed = collect($obstacles)->firstWhere('id', $selected);
                        @endphp
                        <p class="mt-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Step :number · :name, as configured now', ['number' => collect($obstacles)->search(fn ($item) => $item['id'] === $selected) + 1, 'name' => $registry->find($previewed['type'])?->name()]) }}
                        </p>
                    @else
                        <div class="flex h-48 flex-col items-center justify-center gap-2 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:icon.cursor-arrow-rays class="size-6 text-zinc-300 dark:text-zinc-600" />
                            {{ $obstacles === [] ? __('Add an obstacle to see it here.') : __('Pick an obstacle to see it here.') }}
                        </div>
                    @endif
                </div>
            </x-ntb.panel>

            <section class="relative overflow-hidden rounded-xl bg-ink p-5 text-white dark:bg-zinc-900 dark:ring-1 dark:ring-zinc-800" data-test="routine-workout">
                <div class="flex items-center justify-between">
                    <span class="rounded bg-amber-300 px-2 py-0.5 font-mono text-[0.65rem] font-bold tracking-widest text-ink">{{ __('THE WORKOUT') }}</span>
                    <span class="font-mono text-[0.65rem] tracking-widest text-white/50">{{ trans_choice(':count STEP|:count STEPS', count($plan->steps)) }}</span>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-1.5 text-xs">
                    @forelse ($plan->steps as $step)
                        <span class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1">
                            <flux:icon :icon="$step->obstacle->icon()" variant="micro" class="size-3.5 text-coral-300" />{{ $step->obstacle->name() }}
                        </span>
                        <flux:icon.chevron-right variant="micro" class="size-3 text-white/30" />
                    @empty
                        <span class="text-white/50">{{ __('No obstacles') }}</span>
                        <flux:icon.chevron-right variant="micro" class="size-3 text-white/30" />
                    @endforelse
                    <span class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1">
                        <flux:icon :icon="GymExit::from($exitAction)->icon()" variant="micro" class="size-3.5 text-emerald-300" />{{ GymExit::from($exitAction)->label() }}
                    </span>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs text-white/60">{{ __('A person spends about') }}</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-white tabular-nums">{{ HashPuzzle::describeSeconds($plan->humanSeconds()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-white/60">{{ __('A bot’s CPU burns about') }}</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-coral-400 tabular-nums">{{ Number::abbreviate($plan->workHashes(), maxPrecision: 1) }}</dd>
                        <dd class="text-[0.65rem] text-white/50">{{ __('hashes, every visit') }}</dd>
                    </div>
                </dl>

                @if ($this->stackedWith->isNotEmpty())
                    <p class="mt-5 border-t border-white/10 pt-4 text-xs text-white/60">
                        {{ __('Stacks with :routines. A visitor who matches several gets every routine’s obstacles, each once, in routine order.', ['routines' => $this->stackedWith->pluck('name')->join(', ', ' '.__('and').' ')]) }}
                    </p>
                @endif
            </section>
        </aside>
    </div>

    {{-- Obstacle library --}}
    <flux:modal name="obstacle-library" class="w-full max-w-2xl">
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Obstacle library') }}</flux:heading>
            <flux:text>{{ __('Each obstacle is a plugin. Add one to the end of the pipeline, then drag it where you want it.') }}</flux:text>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2" data-test="obstacle-library">
            @foreach ($registry->all() as $candidate)
                @php
                    $defaults = $candidate->configure([]);
                    $isUsed = collect($obstacles)->contains('type', $candidate->key());
                @endphp
                <button
                    type="button"
                    wire:click="addObstacle('{{ $candidate->key() }}')"
                    wire:key="library-{{ $candidate->key() }}"
                    class="flex flex-col rounded-xl border border-line p-4 text-start transition hover:border-coral-400 disabled:opacity-60 dark:border-zinc-800 dark:hover:border-coral-500"
                    data-test="library-obstacle"
                >
                    <span class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-coral-50 text-coral-600 dark:bg-coral-500/10 dark:text-coral-300">
                            <flux:icon :icon="$candidate->icon()" variant="mini" class="size-5" />
                        </span>
                        <span class="font-semibold">{{ $candidate->name() }}</span>
                        @if ($isUsed)
                            <flux:badge size="sm" class="ms-auto">{{ __('In this routine') }}</flux:badge>
                        @endif
                    </span>
                    <span class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $candidate->tagline() }}</span>
                    <span class="mt-3 flex gap-3 font-mono text-[0.65rem] text-zinc-500 dark:text-zinc-400">
                        <span title="{{ __('Time a person spends, with default settings') }}">⏱ {{ HashPuzzle::describeSeconds($candidate->humanSeconds($defaults)) }}</span>
                        @if ($candidate->workHashes($defaults) > 0)
                            <span title="{{ __('Hashes a bot burns, with default settings') }}">⚙ {{ Number::abbreviate($candidate->workHashes($defaults), maxPrecision: 1) }}</span>
                        @endif
                        @if ($candidate->flagsWhen())
                            <span title="{{ $candidate->flagsWhen() }}">⚑ {{ __('can escalate') }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    </flux:modal>
</div>
