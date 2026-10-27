{{-- Every sign-in page: the form on one side, the ink brand panel (art, a line of copy, the tagline) on the other. --}}
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
