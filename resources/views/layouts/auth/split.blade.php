@props([
    'title' => null,
    'art' => 'auth-door',
    'artAlt' => '',
    'line' => null,
    'note' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title])

        <style>
            /* The sign-in pages: coral is the primary colour on the form side (the rest of the site's buttons are ink). */
            .auth-form {
                --color-accent: var(--color-coral-500);
                --color-accent-content: var(--color-coral-700);
                --color-accent-foreground: var(--color-ink);
            }

            .dark .auth-form {
                --color-accent-content: var(--color-coral-400);
            }

            .auth-form [data-flux-button],
            .auth-form input[data-flux-control]:not([data-flux-otp-input]) {
                border-radius: 0.75rem;
            }

            /* The focus ring's gap is the form's own background, not the coral button's ink text colour. */
            .auth-form input:focus[data-flux-control] {
                --tw-ring-offset-color: #fff;
            }

            .dark .auth-form input:focus[data-flux-control] {
                --tw-ring-offset-color: var(--color-zinc-900);
            }
        </style>
    </head>
    <body class="min-h-screen bg-white text-ink antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <div class="grid lg:min-h-svh lg:grid-cols-2">
            {{-- The form comes first in the page, so on a phone it's the first thing you see. --}}
            <main class="auth-form flex min-w-0 flex-col px-4 pt-5 pb-10 sm:px-10 lg:order-2 lg:px-16 lg:pt-8">
                <div class="flex items-center justify-between gap-4">
                    <x-app-logo href="{{ route('home') }}" aria-label="{{ __('NiceTryBot home') }}" />

                    <a href="{{ route('home') }}" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-zinc-600 transition hover:text-coral-700 dark:text-zinc-400 dark:hover:text-coral-400">
                        <flux:icon.arrow-left variant="micro" />
                        {{ __('Back to the site') }}
                    </a>
                </div>

                <div class="flex flex-1 items-center pt-10 lg:py-16">
                    <div class="mx-auto w-full max-w-sm">
                        {{ $slot }}
                    </div>
                </div>
            </main>

            {{-- The brand panel is always ink, so it's drawn with the dark theme in light mode too (the art flips to white ink). --}}
            <aside class="dark flex min-w-0 flex-col gap-8 bg-ink px-4 py-10 text-white sm:px-10 lg:sticky lg:top-0 lg:order-1 lg:h-svh lg:gap-10 lg:px-14 lg:py-12">
                <x-eyebrow>{{ __('Real humans only') }}</x-eyebrow>

                {{-- min-h-0 lets the art shrink on a short window, so the copy never spills off the ink. --}}
                <div class="flex min-h-0 flex-1 items-center justify-center">
                    <img src="{{ asset('images/art/'.$art.'.png') }}" alt="{{ $artAlt }}" class="art max-h-52 w-full max-w-xl object-contain sm:max-h-72 lg:max-h-[min(22rem,100%)]" />
                </div>

                @if (filled($line))
                    <div>
                        <p class="max-w-lg text-2xl leading-tight font-bold tracking-tight text-balance sm:text-4xl">{{ $line }}</p>

                        @if (filled($note))
                            <p class="mt-4 max-w-md text-white/70">{{ $note }}</p>
                        @endif
                    </div>
                @endif

                <p class="text-xs text-pretty text-white/50">© {{ now()->year }} NiceTryBot · {{ __('No bots were harmed. A few were made to jog.') }}</p>
            </aside>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
