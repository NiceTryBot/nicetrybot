@props([
    'label',
    'value',
    'icon' => null,
    'change' => null,
    'changeLabel' => null,
    'risingIsGood' => null,
    'hint' => null,
    'href' => null,
])

@php
    $changeTone = match (true) {
        $change === null || $change == 0 || $risingIsGood === null => 'text-zinc-500 dark:text-zinc-400',
        ($change > 0) === $risingIsGood => 'text-emerald-700 dark:text-emerald-400',
        default => 'text-coral-700 dark:text-coral-300',
    };
@endphp

<{{ $href ? 'a' : 'div' }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'rounded-xl border border-line bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900',
        'block transition hover:border-zinc-300 hover:shadow-sm dark:hover:border-zinc-700' => $href,
    ]) }}
>
    <div class="flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <span>{{ $label }}</span>

        @if ($icon)
            <flux:icon :icon="$icon" variant="mini" class="size-4 text-zinc-400 dark:text-zinc-500" />
        @endif
    </div>

    <div class="mt-3 text-3xl font-semibold tracking-tight text-ink tabular-nums dark:text-white">{{ $value }}</div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
        @if ($change !== null)
            <span @class(['inline-flex items-center gap-0.5 font-semibold', $changeTone])>
                <flux:icon :icon="$change >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" class="size-3.5" />
                {{ number_format(abs($change), 1) }}%
            </span>
            <span>{{ $changeLabel }}</span>
        @elseif ($hint)
            <span>{{ $hint }}</span>
        @endif
    </div>
</{{ $href ? 'a' : 'div' }}>
