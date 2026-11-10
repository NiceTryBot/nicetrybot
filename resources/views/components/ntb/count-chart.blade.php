@props([
    // Bars to draw: each an array{key, label, start, end, count}, oldest first.
    'buckets',
    // What one bar counts, for the legend, the tooltip and the screen-reader table (e.g. "Clients dropped").
    'label',
    'heightClass' => 'h-48',
    // The bars' fill, as a Tailwind background class.
    'color' => 'bg-(--chart-human)',
])

@php
    $counts = collect($buckets)->map(fn (array $bucket): int => (int) $bucket['count']);
    // A burst many times the usual would flatten every other bar, so the scale stops at the tallest bar
    // within five times the usual (the median of the bars that have any), and a taller bar is cut short
    // with a break across its top. Too few bars have no usual to go by.
    $nonEmpty = $counts->filter();
    $usualCeiling = $nonEmpty->count() >= 8 ? 5 * $nonEmpty->median() : PHP_INT_MAX;
    $largest = max(1, (int) $counts->filter(fn (int $count) => $count <= $usualCeiling)->max());
    $magnitude = 10 ** floor(log10($largest));
    $scale = collect([1, 2, 2.5, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($candidate) => $candidate >= $largest);
    $cutsShort = $counts->contains(fn (int $count) => $count > $scale);
    // The break: a slanted gap a little below the top of a bar that's cut short.
    $breakMask = 'mask-image: linear-gradient(165deg, #000 7px, transparent 7px 10px, #000 10px)';
    $count = count($buckets);
    $labelEvery = match (true) {
        // About seven labels, on steps a clock divides evenly (every 4 hours, every day…).
        $count > 60 => collect([6, 8, 12, 16, 24, 32, 48, 72, 96, 144, 192, 288])->first(fn (int $step) => $step >= $count / 7) ?? (int) ceil($count / 7),
        $count > 30 => 10,
        $count > 24 => 5,
        $count > 12 => 4,
        default => 1,
    };
    // Many bars sit closer together, with less rounding, so each keeps some width.
    $gapClass = match (true) {
        $count > 120 => 'gap-0',
        $count > 60 => 'gap-px',
        default => 'gap-[3px]',
    };
    $roundedClass = $count > 60 ? 'rounded-t-[1px]' : 'rounded-t-[4px]';
    $gridSteps = [1, 0.75, 0.5, 0.25, 0];
@endphp

<div {{ $attributes }}>
    {{ $heading ?? '' }}

    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-300">
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $color }}"></span>{{ $label }}</span>
        @if ($cutsShort)
            <span class="inline-flex items-center gap-1.5" title="{{ __('These bars are too tall to fit with the rest. Hover one for its count.') }}" data-test="count-chart-cut-short"><span class="h-3.5 w-1.5 rounded-t-[2px] bg-zinc-400" style="mask-image: linear-gradient(165deg, #000 3px, transparent 3px 5px, #000 5px)"></span>{{ __('Cut short to fit') }}</span>
        @endif
        @isset($actions)
            <div class="ms-auto flex items-center gap-3">{{ $actions }}</div>
        @endisset
    </div>

    <div class="flex gap-2">
        <div @class(['relative w-8 shrink-0 text-end font-mono text-[0.65rem] text-zinc-400 tabular-nums', $heightClass]) aria-hidden="true">
            @foreach ($gridSteps as $step)
                <span class="absolute end-0 -translate-y-1/2" style="top: {{ (1 - $step) * 100 }}%">{{ \Illuminate\Support\Number::abbreviate($scale * $step, maxPrecision: 2) }}</span>
            @endforeach
        </div>

        <div class="relative min-w-0 flex-1">
            <div @class(['relative', $heightClass])>
                @foreach ($gridSteps as $step)
                    <div class="absolute inset-x-0 border-t border-dashed border-zinc-200 dark:border-zinc-800" style="top: {{ (1 - $step) * 100 }}%" aria-hidden="true"></div>
                @endforeach

                <div class="absolute inset-0 flex items-end {{ $gapClass }}" aria-hidden="true">
                    @foreach ($buckets as $bucket)
                        @php
                            $tooltipAlignment = match (true) {
                                $loop->index < 2 => 'start-0',
                                $loop->remaining < 2 => 'end-0',
                                default => 'start-1/2 -translate-x-1/2',
                            };
                            $value = (int) $bucket['count'];
                            // A bar may carry its own fill (a CSS color), to tint it by a second measure
                            // like the time a tarpit held clients, and an extra tooltip line to name it.
                            $fill = $bucket['fill'] ?? null;
                        @endphp

                        <div
                            class="group relative flex h-full min-w-0 flex-1 flex-col justify-end rounded-sm outline-none transition-opacity hover:bg-zinc-100/70 focus-visible:bg-zinc-100 dark:hover:bg-white/5"
                            tabindex="0"
                            wire:key="count-bar-{{ $bucket['key'] }}"
                        >
                            @if ($value > 0)
                                <div
                                    @class(['w-full', $roundedClass, $color => $fill === null])
                                    style="height: {{ min(1, $value / $scale) * 100 }}%{{ $fill !== null ? "; background: {$fill}" : '' }}{{ $value > $scale ? "; {$breakMask}" : '' }}"
                                    @if ($value > $scale) data-test="count-chart-bar-cut-short" @endif
                                ></div>
                            @endif

                            <div @class(['pointer-events-none absolute bottom-full z-20 mb-2 hidden w-max rounded-lg bg-ink px-3 py-2 text-xs text-white shadow-lg group-hover:block group-focus-visible:block dark:bg-zinc-700', $tooltipAlignment])>
                                <p class="mb-1 font-semibold">{{ $bucket['label'] }}</p>
                                <p class="flex items-center gap-1.5"><span class="size-2 rounded-sm {{ $fill === null ? $color : '' }}" @if ($fill !== null) style="background: {{ $fill }}" @endif></span>{{ $label }} <span class="ms-auto ps-3 font-mono tabular-nums">{{ number_format($value) }}</span></p>
                                @if (! empty($bucket['note']))
                                    <p class="mt-1 border-t border-white/15 pt-1 text-white/70">{{ $bucket['note'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div @class(['mt-2 flex font-mono text-[0.65rem] text-zinc-400', $gapClass]) aria-hidden="true">
                @foreach ($buckets as $bucket)
                    <span class="min-w-0 flex-1 overflow-visible whitespace-nowrap">{{ $loop->index % $labelEvery === 0 ? $bucket['label'] : '' }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <table class="sr-only">
        <caption>{{ $label }}</caption>
        <thead>
            <tr>
                <th>{{ __('Period') }}</th>
                <th>{{ $label }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($buckets as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td>{{ (int) $bucket['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
