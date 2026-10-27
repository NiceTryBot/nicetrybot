@props([
    'title' => null,
    'banner' => null,
    // The team's contact for a visitor who can't get through, from GymSession::visitorHelp().
    'help' => null,
])

{{-- The gym's visitor pages. Neutral on purpose: they answer on customers' domains and carry no attribution. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex, nofollow" />
        <title>{{ $title ?? __('One moment…') }}</title>
        @vite('resources/css/gym.css')
    </head>
    <body class="flex min-h-screen flex-col bg-canvas font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        @if ($banner)
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-test="gym-banner">
                {{ $banner }}
            </div>
        @endif

        <main class="flex flex-1 flex-col items-center justify-center px-4 py-8">
            {{ $slot }}
        </main>

        @if ($help)
            <footer class="px-4 pb-6 text-center text-xs text-zinc-500 dark:text-zinc-400" data-test="gym-help">
                {{ __('Having trouble?') }}
                <a href="{{ $help['href'] }}" class="underline hover:text-ink dark:hover:text-zinc-100" rel="noopener">{{ __('Contact :contact', ['contact' => $help['contact']]) }}</a>
                {{ __('and quote reference') }} <span class="font-mono select-all">{{ $help['reference'] }}</span>.
            </footer>
        @endif
    </body>
</html>
