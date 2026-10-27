@props([
    'code' => null,
    'showCode' => true,
])

{{-- A country as its flag plus ISO code, with the full name on hover. Falls back to the code alone. --}}
@if (filled($code))
    @php
        $code = strtoupper($code);
        $name = \Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code;
    @endphp
    <span {{ $attributes->class('inline-flex items-center gap-1 align-middle') }} title="{{ $name }}" data-test="country">
        @if (\Flux\AssetManager::hasFlag($code))
            <flux:flag :country="$code" size="xs" :alt="$name" class="!w-4" />
        @endif
        @if ($showCode)
            <span class="font-mono">{{ $code }}</span>
        @else
            <span class="sr-only">{{ $name }}</span>
        @endif
    </span>
@endif
