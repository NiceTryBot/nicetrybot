@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'url' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
        <meta property="og:type" content="article">
        <meta property="og:site_name" content="NiceTryBot">
        <meta property="og:title" content="{{ $title ?? 'NiceTryBot' }}">
        @if ($description)
            <meta property="og:description" content="{{ $description }}">
        @endif
        <meta property="og:url" content="{{ $url ?? url()->current() }}">
        @if ($image)
            <meta property="og:image" content="{{ $image }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="675">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ $image }}">
        @endif
        <meta name="twitter:title" content="{{ $title ?? 'NiceTryBot' }}">
        @if ($description)
            <meta name="twitter:description" content="{{ $description }}">
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-canvas text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-6">
            <x-app-logo href="{{ route('home') }}" />

            <nav class="flex items-center gap-2">
                {{-- Links from packages, from App\Support\ViewSlots. --}}
                {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::PublicNav) }}
                @auth
                    <flux:button variant="primary" size="sm" :href="route('dashboard')">{{ __('Open your workspace') }}</flux:button>
                @else
                    <flux:button variant="ghost" size="sm" :href="route('login')">{{ __('Log in') }}</flux:button>
                @endauth
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-6 pb-16">
            {{ $slot }}
        </main>

        <footer class="mx-auto flex w-full max-w-6xl flex-wrap justify-between gap-2 px-6 py-8 text-xs text-zinc-500 dark:text-zinc-400">
            <span>{{ __('Made for people. Very inconvenient for bots.') }}</span>
            <span>
                {{ __('Numbers are aggregates from the NiceTryBot edge. No customer is named; IPs are cut in half.') }}
                <a href="https://db-ip.com" class="underline" target="_blank" rel="noopener">{{ __('IP geolocation by DB-IP') }}</a>
            </span>
        </footer>

        @fluxScripts
    </body>
</html>
