@props([
    'diagram',
    'unit' => __('requests'),
    'period' => null,
    'domain' => null,
])

@php
    /** @var \App\Data\SankeyDiagram $diagram */
    $headerHeight = 28;
    $columnCount = count($diagram->columns);
    $fillFor = fn (array $node) => match (true) {
        $node['column'] > 0 => 'var(--flow-node)',
        $node['slot'] > 0 => 'var(--flow-'.$node['slot'].')',
        default => 'var(--flow-other)',
    };
    $activityLink = fn (?array $filter) => $filter === null ? null : route('activity.index', array_filter([...$filter, 'period' => $period, 'domain' => $domain], fn ($value) => $value !== null && $value !== ''));
@endphp

<div {{ $attributes }}>
    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('Ribbon color follows the :stage. Hover one to trace it; click any node to see its requests.', ['stage' => Str::lower($diagram->columns[0])]) }}
    </p>

    <div class="overflow-x-auto" x-data="{ origin: null }">
        <svg
            viewBox="-4 0 {{ \App\Data\SankeyDiagram::WIDTH + 8 }} {{ $diagram->height + $headerHeight + 4 }}"
            class="h-auto w-full min-w-[720px]"
            role="img"
            aria-label="{{ __('Flow of :total :unit through :stages', ['total' => Number::format($diagram->total), 'unit' => $unit, 'stages' => implode(', ', $diagram->columns)]) }}"
        >
            @foreach ($diagram->columns as $index => $title)
                <text x="{{ $diagram->columnX[$index] }}" y="14" class="fill-zinc-500 text-[11px] font-semibold tracking-wide uppercase dark:fill-zinc-400">{{ $title }}</text>
            @endforeach

            <g transform="translate(0 {{ $headerHeight }})">
                @foreach ($diagram->ribbons as $ribbon)
                    <path
                        d="{{ $ribbon['d'] }}"
                        class="sankey-ribbon"
                        style="fill: {{ $ribbon['slot'] > 0 ? 'var(--flow-'.$ribbon['slot'].')' : 'var(--flow-other)' }}"
                        x-bind:class="{ 'sankey-faded': origin !== null && origin !== {{ $ribbon['slot'] }} }"
                    ><title>{{ $ribbon['from'] }} → {{ $ribbon['to'] }}: {{ Number::format($ribbon['value']) }} {{ $unit }}</title></path>
                @endforeach

                @foreach ($diagram->nodes as $node)
                    @php
                        $href = $activityLink($node['filter']);
                        $tooltip = trim($node['label']."\n".($node['detail'] && $node['detail'] !== $node['label'] ? $node['detail']."\n" : '').Number::format($node['value']).' '.$unit);
                    @endphp
                    <g
                        @if ($node['column'] === 0) x-on:mouseenter="origin = {{ $node['slot'] }}" x-on:mouseleave="origin = null" @endif
                        data-test="sankey-node"
                    >
                        @if ($href)<a href="{{ $href }}">@endif
                            <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="{{ \App\Data\SankeyDiagram::NODE_WIDTH }}" height="{{ $node['height'] }}" rx="2" style="fill: {{ $fillFor($node) }}">
                                <title>{{ $tooltip }}</title>
                            </rect>
                            <text
                                    x="{{ $node['x'] + \App\Data\SankeyDiagram::NODE_WIDTH + 6 }}"
                                    y="{{ $node['y'] + $node['height'] / 2 }}"
                                    dominant-baseline="middle"
                                    @class(['sankey-label text-[11px]', 'font-semibold' => str_starts_with($node['key'], 'category:'), 'fill-zinc-800 dark:fill-zinc-100' => $node['key'] !== \App\Data\SankeyDiagram::OTHER_KEY, 'fill-zinc-500 dark:fill-zinc-400' => $node['key'] === \App\Data\SankeyDiagram::OTHER_KEY, 'hover:underline' => $href !== null])
                                ><title>{{ $tooltip }}</title>{{ Str::limit($node['label'], $node['column'] === $columnCount - 1 ? $diagram->lastLabelLength : $diagram->labelLength) }}<tspan class="fill-zinc-400 dark:fill-zinc-500"> · {{ Number::abbreviate($node['value']) }}</tspan></text>
                        @if ($href)</a>@endif
                    </g>
                @endforeach
            </g>
        </svg>
    </div>

    <details class="group mt-4 text-sm">
        <summary class="cursor-pointer text-xs font-medium text-zinc-600 select-none hover:text-ink dark:text-zinc-300 dark:hover:text-white">
            {{ __('Show as table') }} <span class="text-zinc-400">({{ __(':count paths', ['count' => count($diagram->paths)]) }})</span>
        </summary>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="text-zinc-500 dark:text-zinc-400">
                    <tr>
                        @foreach ($diagram->columns as $title)
                            <th class="py-1.5 pe-4 font-medium">{{ $title }}</th>
                        @endforeach
                        <th class="py-1.5 text-end font-medium">{{ Str::ucfirst($unit) }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach (array_slice($diagram->paths, 0, 50) as $path)
                        <tr>
                            @foreach ($diagram->columns as $index => $title)
                                <td class="max-w-64 truncate py-1.5 pe-4 font-mono" title="{{ $path['labels'][$index] ?? '' }}">{{ $path['labels'][$index] ?? '—' }}</td>
                            @endforeach
                            <td class="py-1.5 text-end font-mono tabular-nums">{{ Number::format($path['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
