@props([
    'network' => null,
])

{{-- What a network is for, if anyone knows. Load the network with its networkCategory. --}}
@if ($category = $network?->shownCategory())
    <flux:badge size="sm" :color="$category->color()" {{ $attributes->class('shrink-0') }} data-test="network-category-badge">{{ $category->label() }}</flux:badge>
@endif
