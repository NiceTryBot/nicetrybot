{{-- Kept for the starter kit's name: it's the branded split frame, so no page can fall back to the stock look. --}}
@props([
    'title' => null,
    'art' => 'auth-door',
    'artAlt' => '',
    'line' => null,
    'note' => null,
])

<x-layouts::auth.split :title="$title" :art="$art" :art-alt="$artAlt" :line="$line" :note="$note">
    {{ $slot }}
</x-layouts::auth.split>
