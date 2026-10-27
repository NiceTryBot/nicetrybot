@props([
    'dot' => 'coral',
])

<p {{ $attributes->class('flex items-center gap-2 font-mono text-xs tracking-[0.14em] uppercase text-zinc-600 dark:text-zinc-400') }}>
    <span @class(['size-1.5 rounded-full', 'bg-coral-500' => $dot === 'coral', 'bg-emerald-500' => $dot === 'green'])></span>
    {{ $slot }}
</p>
