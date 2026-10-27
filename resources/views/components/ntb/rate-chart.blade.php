@props([
    'series',
    'unitLabel',
    'heightClass' => 'h-56',
])

@php
    $points = $series->points;
    $count = count($points);
    $lines = array_values(array_filter([
        ['key' => 'requests', 'label' => __('All requests'), 'color' => 'var(--chart-human)', 'dashed' => false],
        ['key' => 'suspicious', 'label' => __('Suspicious'), 'color' => 'var(--chart-suspicious)', 'dashed' => false],
        $series->hasPeak() ? ['key' => 'peak', 'label' => __('Busiest :unit', ['unit' => $series->unit === 1 ? __('second') : __('minute')]), 'color' => 'var(--color-zinc-400)', 'dashed' => true] : null,
    ]));

    $largest = max(0, ...array_map(fn (array $point) => max($point['requests'], $series->hasPeak() ? $point['peak'] : 0), $points ?: [['requests' => 0, 'peak' => 0]]));
    $largest = $largest > 0 ? $largest : 1;
    $magnitude = 10 ** floor(log10($largest));
    $scale = collect([1, 2, 2.5, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($candidate) => $candidate >= $largest);
    $gridSteps = [1, 0.75, 0.5, 0.25, 0];

    $width = 1000;
    $height = 200;
    $x = fn (int $index) => $count > 1 ? round($index / ($count - 1) * $width, 2) : 0;
    $y = fn (float $value) => round($height - $value / $scale * $height, 2);
    $polylines = collect($lines)->mapWithKeys(fn (array $line) => [
        $line['key'] => collect($points)->map(fn (array $point, int $index) => $x($index).','.$y((float) $point[$line['key']]))->implode(' '),
    ]);

    $firstTime = $points[0]['t'] ?? 0;
    $lastTime = $points[$count - 1]['t'] ?? 0;
    $span = max(1, $lastTime - $firstTime);
    $tickStep = collect([60, 120, 300, 600, 900, 1_800, 3_600, 7_200, 10_800, 21_600, 43_200, 86_400])->first(fn (int $step) => $span / $step <= 6, 86_400);
    $tickFormat = $tickStep >= 86_400 ? 'M j' : 'H:i';
    $ticks = [];
    for ($tick = (int) ceil($firstTime / $tickStep) * $tickStep; $tick <= $lastTime; $tick += $tickStep) {
        $ticks[] = ['left' => ($tick - $firstTime) / $span * 100, 'label' => \Carbon\CarbonImmutable::createFromTimestamp($tick)->format($tickFormat)];
    }
@endphp

<div {{ $attributes }}>
    <div class="mb-3 font-mono text-[0.65rem] text-zinc-400">{{ $unitLabel }}</div>

    <div class="flex gap-2">
        <div @class(['relative w-10 shrink-0 text-end font-mono text-[0.65rem] text-zinc-400 tabular-nums', $heightClass]) aria-hidden="true">
            @foreach ($gridSteps as $step)
                <span class="absolute end-0 -translate-y-1/2" style="top: {{ (1 - $step) * 100 }}%">{{ \Illuminate\Support\Number::abbreviate($scale * $step, maxPrecision: 2) }}</span>
            @endforeach
        </div>

        <div class="relative min-w-0 flex-1">
            <div
                @class(['relative', $heightClass])
                wire:key="rate-chart-{{ $series->unit }}-{{ md5(json_encode($points)) }}"
                x-data="{
                    points: @js($points),
                    lines: @js($lines),
                    hover: null,
                    track(event) {
                        const bounds = this.$el.getBoundingClientRect();
                        const index = Math.round(((event.clientX - bounds.left) / bounds.width) * (this.points.length - 1));
                        this.hover = Math.max(0, Math.min(this.points.length - 1, index));
                    },
                    format(value) {
                        return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
                    },
                }"
                x-on:mousemove="track($event)"
                x-on:mouseleave="hover = null"
                role="img"
                aria-label="{{ __(':unit over the range, peaking at :peak.', ['unit' => $unitLabel, 'peak' => number_format($series->peak()['requests'])]) }}"
            >
                @foreach ($gridSteps as $step)
                    <div class="absolute inset-x-0 border-t border-zinc-200 dark:border-zinc-800" style="top: {{ (1 - $step) * 100 }}%" aria-hidden="true"></div>
                @endforeach

                <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible" aria-hidden="true">
                    @foreach (array_reverse($lines) as $line)
                        <polyline
                            points="{{ $polylines[$line['key']] }}"
                            fill="none"
                            stroke-width="1.5"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                            @if ($line['dashed']) stroke-dasharray="4 3" @endif
                            style="stroke: {{ $line['color'] }}"
                        />
                    @endforeach
                </svg>

                <template x-if="hover !== null">
                    <div class="pointer-events-none absolute inset-y-0" :style="`left: ${points.length > 1 ? hover / (points.length - 1) * 100 : 0}%`">
                        <div class="absolute inset-y-0 border-l border-zinc-400 dark:border-zinc-500"></div>
                        <div
                            class="absolute top-0 z-20 w-max rounded-lg bg-ink px-3 py-2 text-xs text-white shadow-lg dark:bg-zinc-700"
                            :class="hover > points.length / 2 ? 'end-2' : 'start-2'"
                        >
                            <p class="mb-1 font-semibold" x-text="points[hover].label"></p>
                            <template x-for="line in lines" :key="line.key">
                                <p class="flex items-center gap-1.5">
                                    <span class="h-0.5 w-3 rounded-full" :style="`background: ${line.color}`"></span>
                                    <span x-text="line.label"></span>
                                    <span class="ms-auto ps-4 font-mono tabular-nums" x-text="format(points[hover][line.key])"></span>
                                </p>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="relative mt-2 h-4 font-mono text-[0.65rem] text-zinc-400" aria-hidden="true">
                @foreach ($ticks as $tick)
                    <span class="absolute -translate-x-1/2 whitespace-nowrap" style="left: {{ $tick['left'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 ps-12 text-xs text-zinc-600 dark:text-zinc-300">
        @foreach ($lines as $line)
            <span class="inline-flex items-center gap-1.5">
                <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true"><line x1="0" x2="16" y1="4" y2="4" stroke-width="2" @if ($line['dashed']) stroke-dasharray="4 3" @endif style="stroke: {{ $line['color'] }}" /></svg>
                {{ $line['label'] }}
            </span>
        @endforeach
    </div>
</div>
