@props([
    'neighborhood',
    'scale' => 0,
])

{{-- An enriched IPv4 address, octet by octet, each under a bar for how busy the prefix it ends was: its /8, /16, /24, then the address itself. Bars share one log scale across the page, so rows compare. --}}
@php
    $barArea = 28;
    $logScale = log10(max($scale, 1) + 1);
@endphp

<span {{ $attributes->class('inline-flex items-end font-mono') }} data-test="prefix-ladder">
    @foreach ($neighborhood->rungs() as $rung)
        @if (! $loop->first)
            <span class="text-zinc-400 dark:text-zinc-500">.</span>
        @endif
        <span
            tabindex="0"
            aria-label="{{ $rung['label'] }}"
            class="group/rung relative inline-flex flex-col items-center rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-coral-500/50"
            data-prefix="{{ $rung['prefix'] }}"
        >
            <span class="flex h-7 w-full items-end justify-center" aria-hidden="true">
                <span
                    @class([
                        'w-[70%] min-w-2 rounded-t-[2px] transition-opacity group-hover/rung:opacity-60 group-focus/rung:opacity-60',
                        'bg-zinc-400 dark:bg-zinc-500' => $rung['prefix'] < 24,
                        'bg-violet-500 dark:bg-violet-400' => $rung['prefix'] === 24,
                        'bg-coral-500 dark:bg-coral-400' => $rung['prefix'] === 32,
                    ])
                    style="height: {{ max(2, round(log10($rung['count'] + 1) / $logScale * $barArea, 1)) }}px"
                ></span>
            </span>
            <span>{{ $rung['octet'] }}</span>
            <span
                role="tooltip"
                class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-md border border-line bg-white px-1.5 py-0.5 font-sans text-[0.65rem] whitespace-nowrap text-ink shadow-sm group-hover/rung:block group-focus/rung:block dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >{{ $rung['label'] }}</span>
        </span>
    @endforeach
</span>
