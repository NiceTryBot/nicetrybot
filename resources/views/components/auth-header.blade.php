@props([
    'title',
    'description' => null,
    'eyebrow' => null,
])

<div {{ $attributes->class('flex w-full flex-col') }}>
    @if (filled($eyebrow))
        <x-eyebrow class="mb-3">{{ $eyebrow }}</x-eyebrow>
    @endif

    <h1 class="text-3xl font-bold tracking-tight text-ink dark:text-white">{{ $title }}</h1>

    @if (filled($description))
        <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
    @endif
</div>
