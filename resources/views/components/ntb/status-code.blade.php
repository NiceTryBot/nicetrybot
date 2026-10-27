@props(['code'])

@php
    $tone = match (true) {
        $code === 404 => 'bg-coral-50 text-coral-700 ring-coral-200 dark:bg-coral-500/10 dark:text-coral-300 dark:ring-coral-500/30',
        $code >= 500 => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
        $code >= 400 => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        $code >= 300 => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
    };
@endphp

<span {{ $attributes->class(['inline-flex rounded px-1.5 py-0.5 font-mono text-xs font-semibold ring-1 ring-inset', $tone]) }}>{{ $code }}</span>
