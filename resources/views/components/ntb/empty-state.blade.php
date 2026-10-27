@props([
    'heading',
    'image' => 'images/bigfoot.png',
])

<div {{ $attributes->class('flex flex-col items-center px-6 py-12 text-center') }}>
    <img src="{{ asset($image) }}" alt="" class="mb-6 w-full max-w-xs mix-blend-multiply dark:mix-blend-lighten dark:invert dark:hue-rotate-180" />
    <h3 class="text-lg font-semibold tracking-tight text-ink dark:text-white">{{ $heading }}</h3>
    <div class="mt-2 max-w-md text-sm text-zinc-500 dark:text-zinc-400">{{ $slot }}</div>

    @isset($actions)
        <div class="mt-6 flex flex-wrap justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
