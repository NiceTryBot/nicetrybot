@props([
    'buckets',
    'heightClass' => 'h-56',
    // The Livewire property a stretch picked by clicking or dragging across the bars goes into, or null
    // for a chart that only shows.
    'selectable' => null,
    // The Livewire property clicking the Spoofed or Outdated key sets ("spoofed", "outdated"), for a
    // chart whose buckets split the requests that passed by their clients.
    'clientFilter' => null,
    // Whether the outdated, spoofed and suspicious bars can each be colored or left blue with the rest,
    // from dots at the top right. They start blue, and each viewer's picks are remembered.
    'colorToggles' => false,
])

@php
    // Buckets that split the requests that passed by what their clients gave away (spoofed, outdated)
    // stack those apart from the clean rest. Series run bottom to top, as the legend reads.
    $splitsClients = array_key_exists('spoofed', $buckets[0] ?? []);
    // A series whose color is off ("shown" on the chart's root unset) draws blue, merged into the bottom
    // of the bar, and leaves the key. Each shows (and hides) by its own literal classes so Tailwind finds them.
    $series = array_values(array_filter([
        ['key' => 'clean', 'label' => $splitsClients ? __('Clean') : __('Humans'), 'color' => 'bg-(--chart-human)', 'filter' => null, 'shown' => ''],
        $splitsClients ? ['key' => 'outdated', 'label' => __('Outdated'), 'color' => 'bg-(--chart-outdated)', 'filter' => 'outdated', 'shown' => 'hidden! group-data-outdated/chart:inline-flex!', 'dot' => 'border-(--chart-outdated) group-data-outdated/chart:bg-(--chart-outdated)'] : null,
        $splitsClients ? ['key' => 'spoofed', 'label' => __('Spoofed'), 'color' => 'bg-(--chart-spoofed)', 'filter' => 'spoofed', 'shown' => 'hidden! group-data-spoofed/chart:inline-flex!', 'dot' => 'border-(--chart-spoofed) group-data-spoofed/chart:bg-(--chart-spoofed)'] : null,
        ['key' => 'suspicious', 'label' => __('Suspicious'), 'color' => 'bg-(--chart-suspicious)', 'filter' => null, 'shown' => 'hidden! group-data-suspicious/chart:inline-flex!', 'dot' => 'border-(--chart-suspicious) group-data-suspicious/chart:bg-(--chart-suspicious)'],
    ]));
    $colorToggles = $colorToggles && $splitsClients;
    $toggled = array_values(array_filter($series, fn (array $item) => $item['key'] !== 'clean'));
    $countOf = fn (array $bucket, string $key): int => $key === 'clean'
        ? $bucket['humans'] - ($bucket['spoofed'] ?? 0) - ($bucket['outdated'] ?? 0)
        : (int) ($bucket[$key] ?? 0);
    // A bar's segments, top down: each colored series (its share 0 while its color is off) over the blue
    // rest, which takes every series whose color is off. --chart-on-<key> is 1 or 0, so CSS redraws the
    // bars the moment a color toggles; a segment keeps 2px off the one above only while both show.
    $segmentsOf = function (array $bucket) use ($toggled, $countOf): array {
        $segments = [];
        $above = [];
        $blue = [(string) $countOf($bucket, 'clean')];

        foreach (array_reverse($toggled) as $item) {
            if (($count = $countOf($bucket, $item['key'])) === 0) {
                continue;
            }

            $on = "var(--chart-on-{$item['key']})";
            $segments[] = ['color' => $item['color'], 'grow' => "calc({$count} * {$on})", 'gap' => $above ? "calc(2px * {$on} * min(1, ".implode(' + ', $above).'))' : '0px'];
            $above[] = "{$count} * {$on}";
            $blue[] = "{$count} * (1 - {$on})";
        }

        $segments[] = ['color' => 'bg-(--chart-human)', 'grow' => 'calc('.implode(' + ', $blue).')', 'gap' => $above ? 'calc(2px * min(1, '.implode(' + ', $above).') * min(1, '.implode(' + ', $blue).'))' : '0px'];

        return $segments;
    };
    // A burst many times the usual traffic would flatten every other bar to nothing, so the scale stops
    // at the tallest bar within five times the usual (the median of the bars that have any), and a
    // taller bar is cut short with a break across its top. Too few bars have no usual to go by.
    $totals = collect($buckets)->map(fn (array $bucket): int => (int) ($bucket['humans'] + $bucket['suspicious']));
    $nonEmpty = $totals->filter();
    $usualCeiling = $nonEmpty->count() >= 8 ? 5 * $nonEmpty->median() : PHP_INT_MAX;
    $largest = max(1, (int) $totals->filter(fn (int $total) => $total <= $usualCeiling)->max());
    $magnitude = 10 ** floor(log10($largest));
    $scale = collect([1, 2, 2.5, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($candidate) => $candidate >= $largest);
    $cutsShort = $totals->contains(fn (int $total) => $total > $scale);
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

{{--
    The root carries data-outdated, data-spoofed and data-suspicious for each color that shows (and
    data-all-colors when they all do), which the key, the dots and the bars' --chart-on-* read. Toggled,
    they're set by Alpine, and wire:ignore.self keeps Livewire's redraws from taking them off.
--}}
<div
    {{ $attributes->class('group/chart [--chart-on-outdated:0] [--chart-on-spoofed:0] [--chart-on-suspicious:0] data-outdated:[--chart-on-outdated:1] data-spoofed:[--chart-on-spoofed:1] data-suspicious:[--chart-on-suspicious:1]') }}
    @if ($colorToggles)
        wire:ignore.self
        x-data="{
            colors: [],
            init() {
                try { this.colors = JSON.parse(localStorage.getItem('nicetrybot:chart-colors')) || []; } catch (e) {}
            },
            toggle(key) {
                this.colors = this.colors.includes(key) ? this.colors.filter((color) => color !== key) : [...this.colors, key];
                try { localStorage.setItem('nicetrybot:chart-colors', JSON.stringify(this.colors)); } catch (e) {}
            },
        }"
        x-effect="['outdated', 'spoofed', 'suspicious'].forEach((key) => $el.toggleAttribute('data-' + key, colors.includes(key))); $el.toggleAttribute('data-all-colors', colors.length === 3)"
    @else
        data-outdated data-spoofed data-suspicious data-all-colors
    @endif
>
    {{ $heading ?? '' }}

    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-300">
        @foreach ($series as $item)
            @if ($clientFilter && $item['filter'])
                <button type="button" wire:click="$set('{{ $clientFilter }}', '{{ $item['filter'] }}')" class="inline-flex items-center gap-1.5 hover:text-ink hover:underline dark:hover:text-white {{ $item['shown'] }}" title="{{ __('List just these') }}" data-test="chart-key-{{ $item['key'] }}">
                    <span class="size-2.5 rounded-sm {{ $item['color'] }}"></span>{{ $item['label'] }}
                </button>
            @elseif ($item['key'] === 'clean' && $colorToggles)
                {{-- It's only clean once every kind of trouble has its own color. --}}
                <span class="inline-flex items-center gap-1.5" data-test="chart-key-clean"><span class="size-2.5 rounded-sm {{ $item['color'] }}"></span><span class="group-data-all-colors/chart:hidden">{{ __('Traffic') }}</span><span class="hidden group-data-all-colors/chart:inline">{{ $item['label'] }}</span></span>
            @else
                <span class="inline-flex items-center gap-1.5 {{ $item['shown'] }}" data-test="chart-key-{{ $item['key'] }}"><span class="size-2.5 rounded-sm {{ $item['color'] }}"></span>{{ $item['label'] }}</span>
            @endif
        @endforeach
        @if ($cutsShort)
            <span class="inline-flex items-center gap-1.5" title="{{ __('These bars are too tall to fit with the rest. Hover one for its counts.') }}" data-test="chart-key-cut-short"><span class="h-3.5 w-1.5 rounded-t-[2px] bg-zinc-400" style="mask-image: linear-gradient(165deg, #000 3px, transparent 3px 5px, #000 5px)"></span>{{ __('Cut short to fit') }}</span>
        @endif
        @if ($selectable)
            <span class="min-w-0 truncate text-zinc-400 max-sm:hidden">{{ __('Click a bar, or drag across bars, to list just that time') }}</span>
        @endif
        @if ($colorToggles || isset($actions))
            <div class="ms-auto flex items-center gap-3">
                @if ($colorToggles)
                    <div class="flex items-center" role="group" aria-label="{{ __('Color the bars') }}" wire:ignore data-test="chart-colors">
                        @foreach ($toggled as $item)
                            <button
                                type="button"
                                x-on:click="toggle(@js($item['key']))"
                                x-bind:aria-pressed="colors.includes(@js($item['key'])).toString()"
                                x-bind:title="(colors.includes(@js($item['key'])) ? @js(__('Stop coloring :series', ['series' => mb_strtolower($item['label'])])) : @js(__('Color :series', ['series' => mb_strtolower($item['label'])])))"
                                class="grid size-5 place-items-center rounded-full transition hover:bg-zinc-100 dark:hover:bg-white/10"
                                aria-label="{{ __('Color :series', ['series' => mb_strtolower($item['label'])]) }}"
                                data-test="chart-color-{{ $item['key'] }}"
                            ><span class="size-2.5 rounded-full border-2 {{ $item['dot'] }}"></span></button>
                        @endforeach
                    </div>
                @endif
                {{ $actions ?? '' }}
            </div>
        @endif
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

                <div
                    @class(['absolute inset-0 flex items-end', $gapClass, 'cursor-pointer touch-pan-y select-none' => $selectable])
                    @if ($selectable)
                        x-data="chartRange(@js($selectable))"
                        x-on:pointerdown="start($event)"
                        x-on:pointermove="move($event)"
                        x-on:pointerup.window="finish()"
                        x-on:pointercancel.window="cancel()"
                    @endif
                    aria-hidden="true"
                >
                    @foreach ($buckets as $bucket)
                        @php
                            $tooltipAlignment = match (true) {
                                $loop->index < 2 => 'start-0',
                                $loop->remaining < 2 => 'end-0',
                                default => 'start-1/2 -translate-x-1/2',
                            };
                            $total = $bucket['humans'] + $bucket['suspicious'];
                        @endphp

                        <div
                            class="group relative flex h-full min-w-0 flex-1 flex-col justify-end rounded-sm outline-none transition-opacity hover:bg-zinc-100/70 focus-visible:bg-zinc-100 dark:hover:bg-white/5"
                            tabindex="0"
                            wire:key="bar-{{ $bucket['key'] }}"
                            @if ($selectable)
                                data-bar-start="{{ $bucket['start'] }}"
                                data-bar-end="{{ $bucket['end'] }}"
                                x-bind:class="{ 'opacity-25': isMuted({{ $bucket['start'] }}, {{ $bucket['end'] }}), 'bg-zinc-100 dark:bg-white/10': isPicked({{ $bucket['start'] }}, {{ $bucket['end'] }}) }"
                                x-on:keydown.enter.prevent="pick({{ $bucket['start'] }}, {{ $bucket['end'] }})"
                                x-on:keydown.space.prevent="pick({{ $bucket['start'] }}, {{ $bucket['end'] }})"
                            @endif
                        >
                            @if ($total > 0)
                                {{-- Drawn top down, so the worst sits on top. --}}
                                <div
                                    @class(['flex w-full flex-col overflow-hidden', $roundedClass])
                                    style="height: {{ min(1, $total / $scale) * 100 }}%{{ $total > $scale ? "; {$breakMask}" : '' }}"
                                    @if ($total > $scale) data-test="chart-bar-cut-short" @endif
                                >
                                    @foreach ($segmentsOf($bucket) as $segment)
                                        <div class="{{ $segment['color'] }} w-full" style="flex: {{ $segment['grow'] }} 1 0px; margin-top: {{ $segment['gap'] }}"></div>
                                    @endforeach
                                </div>
                            @endif

                            <div @class(['pointer-events-none absolute bottom-full z-20 mb-2 hidden w-max rounded-lg bg-ink px-3 py-2 text-xs text-white shadow-lg group-hover:block group-focus-visible:block dark:bg-zinc-700', $tooltipAlignment])>
                                <p class="mb-1 font-semibold">{{ $bucket['label'] }}</p>
                                @foreach (array_reverse($series) as $item)
                                    <p class="flex items-center gap-1.5"><span class="size-2 rounded-sm {{ $item['color'] }}"></span>{{ $item['label'] }} <span class="ms-auto ps-3 font-mono tabular-nums">{{ number_format($countOf($bucket, $item['key'])) }}</span></p>
                                @endforeach
                                <p class="mt-1 border-t border-white/15 pt-1 text-white/70">{{ __(':count 404s', ['count' => number_format($bucket['notFound'])]) }}</p>
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
        <caption>{{ __('Requests per period') }}</caption>
        <thead>
            <tr>
                <th>{{ __('Period') }}</th>
                @foreach ($series as $item)
                    <th>{{ $item['label'] }}</th>
                @endforeach
                <th>{{ __('404s') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($buckets as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    @foreach ($series as $item)
                        <td>{{ $countOf($bucket, $item['key']) }}</td>
                    @endforeach
                    <td>{{ $bucket['notFound'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
