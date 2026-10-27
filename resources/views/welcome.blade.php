<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Real humans only')])
    </head>
    <body class="flex min-h-screen flex-col bg-canvas text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-6">
            <x-app-logo href="{{ route('home') }}" />

            @if (Route::has('login'))
                <nav class="flex items-center gap-2">
                    {{-- Links from packages, from App\Support\ViewSlots. --}}
                    {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::PublicNav) }}
                    @auth
                        <flux:button variant="primary" size="sm" :href="route('dashboard')">{{ __('Open your workspace') }}</flux:button>
                    @else
                        <flux:button variant="ghost" size="sm" :href="route('login')">{{ __('Log in') }}</flux:button>

                        @if (Route::has('register') && config('nicetry.registration') === 'open')
                            <flux:button variant="primary" size="sm" :href="route('register')">{{ __('Get started') }}</flux:button>
                        @endif
                    @endauth
                </nav>
            @endif
        </header>

        <main class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-12 px-6 py-12 lg:grid-cols-2">
            <div>
                <p class="mb-5 flex items-center gap-2 font-mono text-xs tracking-[0.14em] uppercase">
                    <span class="size-1.5 rounded-full bg-emerald-500"></span>
                    {{ __('Small package. Collective defense.') }}
                </p>
                <h1 class="text-4xl leading-[1.05] font-bold tracking-tight sm:text-6xl">
                    {{ __('A bad day for bots.') }}<br>
                    {{ __('A good day for') }} <span class="text-coral-600 dark:text-coral-400">{{ __('humans.') }}</span>
                </h1>
                <p class="mt-6 max-w-lg text-lg text-zinc-600 dark:text-zinc-400">
                    {{ __('Put your domains behind the NiceTryBot edge. See every request and every 404, tag the scanners, and send them to the gym while real people get on with their day.') }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <flux:button variant="primary" :href="auth()->check() ? route('dashboard') : route('login')" icon:trailing="arrow-right">
                        {{ auth()->check() ? __('Open your workspace') : __('Log in to your workspace') }}
                    </flux:button>
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <img src="{{ asset('images/bigfoot.png') }}" alt="{{ __('A cheerful Bigfoot chasing the NiceTryBot robot') }}" class="w-full mix-blend-multiply dark:invert dark:hue-rotate-180 dark:mix-blend-lighten" />

                <div class="mt-4 overflow-hidden rounded-xl bg-ink font-mono text-xs text-white/80">
                    <div class="flex items-center justify-between border-b border-white/10 px-4 py-2 text-white/50">
                        <span>{{ __('a bot\'s very short adventure') }}</span>
                        <span>{{ config('edge.hostname') }}</span>
                    </div>
                    @foreach (['/.env', '/wp-admin', '/.git/config'] as $probePath)
                        <div class="flex items-center gap-4 px-4 py-2">
                            <span class="text-white/40">00:0{{ $loop->index }}</span>
                            <span class="text-white/50">GET</span>
                            <span class="flex-1">{{ $probePath }}</span>
                            <span class="text-coral-400">404</span>
                        </div>
                    @endforeach
                    <div class="flex items-center gap-2 border-t border-white/10 bg-white/5 px-4 py-3 text-white">
                        <span class="text-coral-400">↳</span>
                        {{ __('Pattern spotted.') }} <strong>{{ __('Off to the gym.') }}</strong>
                    </div>
                </div>
            </div>
        </main>

        {{-- Sections from packages, from App\Support\ViewSlots. --}}
        {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::WelcomeSections) }}

        <footer class="mx-auto w-full max-w-6xl px-6 py-8 text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Made for people. Very inconvenient for bots.') }}
        </footer>

        @fluxScripts
    </body>
</html>
