@php
    /** @var \NiceTry\TrafficMap\Data\TreemapTile $tile */
    /** @var string $ns */
@endphp
<div
    wire:key="tm-{{ $ns }}-{{ $tile->key }}"
    class="tm-tile @if ($tile->hot) tm-hot @endif"
    data-size="{{ $tile->size }}"
    style="left: {{ $tile->x }}%; top: {{ $tile->y }}%; width: {{ $tile->w }}%; height: {{ $tile->h }}%; z-index: {{ $tile->requests }};"
    title="{{ $tile->sym }}{{ $tile->sub ? ' · '.$tile->sub : '' }} — {{ \Illuminate\Support\Number::format($tile->requests) }} {{ __('requests') }}, {{ $tile->suspectPct() }}% {{ __('suspect') }}"
>
    <div class="tm-in" style="background-color: {{ $tile->color }};">
        <div class="tm-sym">{{ $tile->sym }}</div>
        @if ($tile->sub)<div class="tm-sub">{{ $tile->sub }}</div>@endif
        <div class="tm-spacer"></div>
        <div class="tm-foot">
            <span class="tm-count">{{ \Illuminate\Support\Number::format($tile->requests) }}</span>
            <span class="tm-delta">{{ $tile->suspectPct() }}%</span>
        </div>
        <div class="tm-bar"><i style="width: {{ $tile->humanPct() }}%"></i></div>
    </div>
</div>
