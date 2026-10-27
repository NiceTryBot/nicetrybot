@props([
    'heading' => null,
    'subheading' => null,
    'padded' => true,
])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-line bg-white dark:border-zinc-800 dark:bg-zinc-900') }}>
    @if ($heading || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 pb-4">
            <div>
                @if ($heading)
                    <h2 class="text-base font-semibold tracking-tight text-ink dark:text-white">{{ $heading }}</h2>
                @endif

                @if ($subheading)
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $subheading }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['px-5 pb-5' => $padded, 'pt-5' => $padded && ! $heading && ! isset($actions)])>
        {{ $slot }}
    </div>
</section>
