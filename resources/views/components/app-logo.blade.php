@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['group flex items-center gap-2.5', 'px-2 py-1' => $sidebar]) }}>
    <x-app-logo-icon class="size-9 shrink-0 text-ink dark:text-white" />

    <span class="grid leading-none">
        <span class="text-lg font-bold tracking-tight text-ink dark:text-white">NiceTry<span class="text-coral-600 dark:text-coral-400">Bot</span></span>
        <span class="mt-1 font-mono text-[0.6rem] tracking-[0.22em] text-zinc-500 dark:text-zinc-400">{{ __('REAL HUMANS ONLY') }}</span>
    </span>
</a>
