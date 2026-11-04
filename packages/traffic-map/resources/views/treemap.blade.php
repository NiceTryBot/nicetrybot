@php
    /** @var \NiceTry\TrafficMap\Data\TreemapData $data */
    $height ??= '32rem';
    $ns = $data->dimension->value.'-'.($data->secondary?->value ?? 'x');
@endphp

@once
    <style>
        .tm-map { position: relative; width: 100%; overflow: hidden; border-radius: 0.625rem; }
        .tm-tile {
            position: absolute; padding: 2px;
            transition: left .6s cubic-bezier(.2,.8,.2,1), top .6s cubic-bezier(.2,.8,.2,1),
                        width .6s cubic-bezier(.2,.8,.2,1), height .6s cubic-bezier(.2,.8,.2,1);
            will-change: left, top, width, height;
        }
        .tm-in {
            position: relative; height: 100%; width: 100%; overflow: hidden;
            border-radius: 6px; border: 1px solid rgba(0,0,0,.28);
            display: flex; flex-direction: column; padding: 9px 11px 0; color: #fff;
            transition: background-color .6s cubic-bezier(.2,.8,.2,1), box-shadow .3s ease;
        }
        .tm-tile.tm-hot .tm-in { box-shadow: inset 0 0 0 1.5px rgba(255,255,255,.72), 0 0 16px rgba(0,0,0,.35); }
        .tm-sym { font-weight: 800; line-height: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 2px rgba(0,0,0,.35); }
        .tm-sub { color: rgba(255,255,255,.72); font-size: 11px; line-height: 1.2; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tm-spacer { flex: 1; min-height: 0; }
        .tm-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; padding-bottom: 9px; }
        .tm-count { font-weight: 800; line-height: .9; font-variant-numeric: tabular-nums; text-shadow: 0 1px 3px rgba(0,0,0,.4); }
        .tm-delta { font-weight: 700; font-size: 12px; white-space: nowrap; color: rgba(255,255,255,.92); font-variant-numeric: tabular-nums; }
        .tm-bar { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%; background: rgba(192,66,54,.9); }
        .tm-bar i { display: block; height: 100%; background: rgba(61,125,140,.95); }
        .tm-tile[data-size="xl"] .tm-sym { font-size: 30px; } .tm-tile[data-size="xl"] .tm-count { font-size: 50px; }
        .tm-tile[data-size="l"] .tm-sym { font-size: 20px; } .tm-tile[data-size="l"] .tm-count { font-size: 34px; }
        .tm-tile[data-size="m"] .tm-sym { font-size: 15px; } .tm-tile[data-size="m"] .tm-count { font-size: 22px; } .tm-tile[data-size="m"] .tm-sub { display: none; }
        .tm-tile[data-size="s"] .tm-in { padding: 6px 8px 0; } .tm-tile[data-size="s"] .tm-sym { font-size: 12px; } .tm-tile[data-size="s"] .tm-count { font-size: 15px; } .tm-tile[data-size="s"] .tm-sub, .tm-tile[data-size="s"] .tm-delta { display: none; }
        .tm-tile[data-size="xs"] .tm-in { padding: 4px 6px 0; justify-content: center; } .tm-tile[data-size="xs"] .tm-sym { font-size: 10px; } .tm-tile[data-size="xs"] .tm-sub, .tm-tile[data-size="xs"] .tm-foot, .tm-tile[data-size="xs"] .tm-bar { display: none; }

        /* two-level: each primary is a block with a header strip and a body of its secondary leaves */
        .tm-grp { position: absolute; padding: 2px; display: flex; flex-direction: column;
            transition: left .6s cubic-bezier(.2,.8,.2,1), top .6s cubic-bezier(.2,.8,.2,1), width .6s cubic-bezier(.2,.8,.2,1), height .6s cubic-bezier(.2,.8,.2,1);
            will-change: left, top, width, height; }
        .tm-grp-hd { flex: 0 0 auto; height: 20px; line-height: 20px; color: #fff; font-weight: 800; font-size: 12px;
            padding: 0 8px; border-radius: 6px 6px 0 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            font-variant-numeric: tabular-nums; text-shadow: 0 1px 2px rgba(0,0,0,.4);
            transition: background-color .6s cubic-bezier(.2,.8,.2,1); }
        .tm-grp-body { position: relative; flex: 1 1 auto; min-height: 0; }
        /* leaves inside a block body fill it (their own 2px padding makes the gaps) */
        .tm-grp-body .tm-tile { padding: 1px; }

        @media (prefers-reduced-motion: reduce) { .tm-tile, .tm-in, .tm-grp, .tm-grp-hd { transition: none; } }
    </style>
@endonce

<div>
    <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400" data-test="traffic-map-legend">
        <span><strong class="font-semibold text-ink tabular-nums dark:text-white">{{ \Illuminate\Support\Number::format($data->requests) }}</strong> {{ __('requests') }}</span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-2 rounded-full" style="background: rgb(194,66,54)"></span>
            <strong class="font-semibold text-ink tabular-nums dark:text-white">{{ $data->suspectPct() }}%</strong> {{ __('suspect') }}
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="text-[0.65rem] uppercase tracking-wide">{{ __('Clean') }}</span>
            <span class="h-2.5 w-24 rounded-full" style="background: linear-gradient(90deg, rgb(46,125,87), rgb(201,167,62), rgb(194,66,54))"></span>
            <span class="text-[0.65rem] uppercase tracking-wide">{{ __('All suspect') }}</span>
        </span>
        <span class="ms-auto">
            @if ($data->twoLevel())
                {{ __(':count groups, split by :secondary', ['count' => \Illuminate\Support\Number::format($data->groups), 'secondary' => $data->secondary->label()]) }}
            @else
                {{ __(':count groups, sized by requests', ['count' => \Illuminate\Support\Number::format($data->groups)]) }}
            @endif
        </span>
    </div>

    @if ($data->isEmpty())
        <div class="tm-map flex items-center justify-center bg-canvas text-sm text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400" style="height: {{ $height }}">
            {{ __('No traffic in this window yet.') }}
        </div>
    @elseif ($data->twoLevel())
        <div class="tm-map bg-canvas dark:bg-zinc-900" style="height: {{ $height }}" data-test="traffic-map">
            @foreach ($data->blocks as $block)
                <div class="tm-grp" style="left: {{ $block->x }}%; top: {{ $block->y }}%; width: {{ $block->w }}%; height: {{ $block->h }}%;" wire:key="tmg-{{ $ns }}-{{ $block->key }}">
                    @if ($block->subdivided)
                        <div class="tm-grp-hd" style="background-color: {{ $block->color }};" title="{{ $block->sym }} — {{ \Illuminate\Support\Number::format($block->requests) }} {{ __('requests') }}, {{ $block->suspectPct() }}% {{ __('suspect') }}">{{ $block->sym }} · {{ \Illuminate\Support\Number::format($block->requests) }} · {{ $block->suspectPct() }}%</div>
                    @endif
                    <div class="tm-grp-body">
                        @foreach ($block->tiles as $tile)
                            @include('traffic-map::_tile', ['tile' => $tile, 'ns' => $ns.'-'.$block->key])
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="tm-map bg-canvas dark:bg-zinc-900" style="height: {{ $height }}" data-test="traffic-map">
            @foreach ($data->tiles as $tile)
                @include('traffic-map::_tile', ['tile' => $tile, 'ns' => $ns])
            @endforeach
        </div>
    @endif
</div>
