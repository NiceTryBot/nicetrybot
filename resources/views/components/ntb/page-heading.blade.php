@props([
    'overline' => null,
    'title',
    'description' => null,
])

<div {{ $attributes->class('mb-8 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between') }}>
    <div class="min-w-0 sm:flex-1 sm:basis-md">
        @if ($overline)
            <p class="mb-2 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ $overline }}</p>
        @endif

        <h1 class="truncate text-3xl font-bold tracking-tight text-ink dark:text-white">{{ $title }}<span class="text-coral-500">.</span></h1>

        @if ($description)
            <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</div>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
