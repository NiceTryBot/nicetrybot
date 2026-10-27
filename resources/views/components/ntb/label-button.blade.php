@props([
    'kind',
    'value' => null,
])

{{-- A small button that opens the label modal for one value, for people who may label. It shows when
     the value is hovered (wrap the value and button in an element with the `group/label` class), when
     focused, and always on touch screens, which can't hover. --}}
@php
    $team = auth()->user()?->currentTeam;
    $kind = $kind instanceof \App\Enums\LabelKind ? $kind : \App\Enums\LabelKind::from($kind);
    $isLabelled = $team !== null && app(\App\Data\LabelDirectory::class)->labelFor($team->id, $kind, $value) !== null;
@endphp

@if ($team && filled($value) && auth()->user()->can('create', [\App\Models\ClientLabel::class, $team]))
    <button
        type="button"
        {{ $attributes->class('inline-flex shrink-0 items-center rounded p-0.5 text-zinc-400 opacity-0 transition group-hover/label:opacity-100 hover:bg-zinc-100 hover:text-ink focus-visible:opacity-100 pointer-coarse:opacity-100 dark:hover:bg-zinc-800 dark:hover:text-white') }}
        wire:click.stop="$dispatch('label-value', { kind: @js($kind->value), value: @js($value) })"
        title="{{ $isLabelled ? __('Rename this :kind', ['kind' => $kind->label()]) : __('Name this :kind', ['kind' => $kind->label()]) }}"
        aria-label="{{ $isLabelled ? __('Rename this :kind', ['kind' => $kind->label()]) : __('Name this :kind', ['kind' => $kind->label()]) }}"
        data-test="label-{{ $kind->value }}"
    >
        <flux:icon.tag variant="micro" class="size-3.5" />
    </button>
@endif
