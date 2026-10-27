@props(['period' => null, 'domain' => null])

@php($query = array_filter(['period' => $period, 'domain' => $domain]))

<nav class="mb-4 flex gap-1 border-b border-line text-sm dark:border-zinc-800" aria-label="{{ __('Analytics views') }}">
    @foreach (['analytics.index' => __('Breakdowns'), 'analytics.flows' => __('Flows'), 'analytics.stats' => __('Stats'), 'analytics.map' => __('Live map')] as $routeName => $tabLabel)
        <a
            href="{{ route($routeName, $query) }}"
            wire:navigate
            @class([
                '-mb-px border-b-2 px-3 py-2 font-medium transition',
                'border-coral-500 text-ink dark:text-white' => request()->routeIs($routeName),
                'border-transparent text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => ! request()->routeIs($routeName),
            ])
            @if (request()->routeIs($routeName)) aria-current="page" @endif
        >{{ $tabLabel }}</a>
    @endforeach
</nav>
