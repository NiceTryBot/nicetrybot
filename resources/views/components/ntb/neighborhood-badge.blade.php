@props([
    'neighborhood' => null,
])

{{-- What stands out about an enriched IP's /24, if anything: one of many hosts sharing it, or nearly the whole of it. --}}
@if ($badge = $neighborhood?->badge())
    <span
        @class([
            'shrink-0 rounded-full px-1.5 py-px font-medium whitespace-nowrap',
            'bg-amber-100 text-amber-800 dark:bg-amber-400/15 dark:text-amber-300' => $badge['color'] === 'amber',
            'bg-sky-100 text-sky-800 dark:bg-sky-400/15 dark:text-sky-300' => $badge['color'] === 'sky',
        ])
        data-test="neighborhood-badge"
    >{{ $badge['label'] }}</span>
@endif
