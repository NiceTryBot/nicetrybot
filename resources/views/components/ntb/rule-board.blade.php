@props(['board', 'candidates' => [], 'labels' => [], 'canManage' => false])

@php
    use App\Enums\RuleAction;
    use App\Support\RuleBoardColumn;

    $tones = [
        'zinc' => 'border-t-zinc-400 text-zinc-600 dark:text-zinc-300',
        'teal' => 'border-t-teal-400 text-teal-700 dark:text-teal-300',
        'amber' => 'border-t-amber-400 text-amber-700 dark:text-amber-300',
        'orange' => 'border-t-orange-400 text-orange-700 dark:text-orange-300',
        'violet' => 'border-t-violet-400 text-violet-700 dark:text-violet-300',
        'rose' => 'border-t-rose-500 text-rose-700 dark:text-rose-300',
        'sky' => 'border-t-sky-400 text-sky-700 dark:text-sky-300',
        'ink' => 'border-t-zinc-900 text-zinc-900 dark:border-t-white dark:text-white',
    ];
@endphp

{{-- One column per thing that can happen to a client, from what asks least of them to what asks most. --}}
@php
    // A filter's rules the team doesn't have yet (a pack's, say) wait in the column they'd act in, or Tag only.
    $columnKeys = array_map(fn (array $entry) => $entry['column']->key, $board);
    $candidatesIn = fn (string $key) => array_values(array_filter($candidates, fn (array $entry) => (in_array($entry['candidate']->column, $columnKeys, true) ? $entry['candidate']->column : RuleBoardColumn::TAG_ONLY) === $key));
@endphp

<div class="grid auto-cols-[minmax(13rem,18rem)] grid-flow-col justify-start gap-3 overflow-x-auto pb-2" data-test="rule-board">
    @foreach ($board as ['column' => $column, 'cards' => $cards])
        @php($waiting = $candidatesIn($column->key))
        <section wire:key="board-{{ $column->key }}" class="min-w-0 rounded-xl border-x border-b border-t-2 border-x-line border-b-line bg-zinc-50 p-2 dark:border-x-zinc-800 dark:border-b-zinc-800 dark:bg-zinc-900/40 {{ $tones[$column->tone] ?? $tones['zinc'] }}" data-test="board-column-{{ $column->key }}">
            <header class="px-1.5 pt-1 pb-2">
                <h3 class="flex items-center gap-1.5 text-sm font-semibold">
                    <flux:icon :icon="$column->icon" variant="mini" class="size-4" />
                    {{ $column->label }}
                    <span class="ms-auto font-mono text-xs font-normal tabular-nums text-zinc-500 dark:text-zinc-400">{{ count($cards) }}</span>
                </h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $column->description }}</p>
            </header>

            <div class="space-y-2">
                @forelse ($cards as ['rule' => $rule, 'destinations' => $destinations, 'links' => $links])
                    @php($ruleScript = rescue(fn () => $rule->script(), report: false))
                    <div
                        wire:key="board-{{ $column->key }}-rule-{{ $rule->id }}"
                        x-data="{ opening: false }"
                        x-on:click="if (opening || $event.target.closest('a, [data-flux-switch]')) return; opening = true; $wire.edit({{ $rule->id }}).finally(() => opening = false)"
                        x-bind:class="opening && 'border-coral-300 ring-2 ring-coral-400/30'"
                        title="{{ $ruleScript?->describe() }}"
                        @class([
                            'relative cursor-pointer rounded-lg border border-line bg-white p-3 text-ink transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-white dark:hover:border-zinc-700',
                            'opacity-60' => ! $rule->enabled,
                        ])
                        data-test="rule-card"
                    >
                        <div x-show="opening" x-cloak class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-white/75 dark:bg-zinc-900/75">
                            <flux:icon.loading class="size-4 text-coral-700 dark:text-coral-300" />
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <button type="button" class="min-w-0 text-start text-sm font-medium break-words">{{ $rule->name }}</button>
                            @if ($canManage)
                                <flux:switch :checked="$rule->enabled" wire:click="toggle({{ $rule->id }})" :aria-label="__('Enable :name', ['name' => $rule->name])" />
                            @endif
                        </div>
                        <p class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <flux:badge size="sm" :color="$rule->action->color()">{{ $rule->action->label() }} · {{ $rule->durationLabel() }}</flux:badge>
                            <x-ntb.rule-fingerprint-mark :destinations="$destinations" />
                            @if ($ruleScript?->tag)
                                <span class="min-w-0 font-mono [overflow-wrap:anywhere] text-[0.65rem]">{{ $ruleScript->tag }}</span>
                            @endif
                        </p>

                        {{-- Where the rule came from (a rule pack), from App\Support\RuleFilters: picking it shows that pack. --}}
                        @foreach ($labels[$rule->id] ?? [] as $label)
                            <a href="{{ route('rules.index', ['filters' => [$label['filter'] => $label['value']]]) }}" wire:navigate class="mt-2 inline-flex max-w-full" title="{{ __('Show only this pack’s rules') }}" data-test="rule-card-label">
                                <span class="inline-flex max-w-full items-start gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    <flux:icon.cube variant="micro" class="mt-0.5 size-3 shrink-0" />
                                    <span class="min-w-0 break-words">{{ $label['label'] }}</span>
                                </span>
                            </a>
                        @endforeach

                        @if (! $ruleScript)
                            {{-- A saved rule stops parsing when the add-on that brings one of its fields is removed. --}}
                            <p class="mt-2 text-xs text-rose-700 dark:text-rose-300" data-test="rule-card-unparsed">{{ __('Doesn’t parse any more, so it doesn’t run. Open it to see why.') }}</p>
                        @elseif ($column->key === RuleBoardColumn::TAG_ONLY && $rule->action === RuleAction::Block)
                            <p class="mt-2 text-xs text-rose-700 dark:text-rose-300" data-test="rule-card-idle-block">{{ __('Says BLOCK, but nothing blocks these clients until a routine takes this rule.') }}</p>
                        @endif

                        @foreach ([...$destinations, ...$links] as $destination)
                            <p class="mt-2 min-w-0 text-xs" data-test="{{ $destination->column === null ? 'rule-card-link' : 'rule-card-destination' }}">
                                <a
                                    href="{{ $destination->url }}"
                                    wire:navigate
                                    @class([
                                        'inline-flex max-w-full items-start gap-1 font-medium underline-offset-2 hover:underline',
                                        'text-zinc-500 dark:text-zinc-400' => $destination->column === null,
                                    ])
                                >
                                    <flux:icon :icon="$destination->icon" variant="micro" class="mt-0.5 size-3 shrink-0" />
                                    <span class="min-w-0 break-words">{{ $destination->label }}</span>
                                </a>
                                @if ($destination->note)
                                    <span class="block ps-4 text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ $destination->note }}</span>
                                @endif
                            </p>
                        @endforeach

                        @if ($rule->active_verdicts_count > 0)
                            <p class="mt-2 text-[0.65rem] text-zinc-500 tabular-nums dark:text-zinc-400">{{ trans_choice(':count active verdict|:count active verdicts', $rule->active_verdicts_count, ['count' => number_format($rule->active_verdicts_count)]) }}</p>
                        @endif
                    </div>
                @empty
                    @if ($waiting === [])
                        <p class="rounded-lg border border-dashed border-line px-3 py-4 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">{{ __('No rules here.') }}</p>
                    @endif
                @endforelse

                @foreach ($waiting as ['filter' => $filterKey, 'candidate' => $candidate])
                    <x-ntb.rule-candidate :filter="$filterKey" :candidate="$candidate" :can-manage="$canManage" />
                @endforeach
            </div>
        </section>
    @endforeach
</div>
