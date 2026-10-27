{{-- Type the code: a plain form, so it works with or without JavaScript. --}}
<div class="text-center">
    <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-coral-500/10">
        <svg class="size-7 text-coral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z" stroke-linejoin="round" />
            <circle cx="12" cy="12" r="3" />
        </svg>
    </div>

    <h1 class="text-xl font-semibold tracking-tight">{{ __('Type the code you see') }}</h1>
    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('A quick check that you’re a person. Letters aren’t case sensitive.') }}</p>

    <img src="{{ $obstacle['image'] }}" alt="{{ __('A code to type, drawn as an image') }}" class="mx-auto mt-6 w-full max-w-xs rounded-lg border border-line dark:border-zinc-700" data-test="screen-code-image" />

    <form method="POST" action="{{ $runtime['attemptUrl'] ?? '#' }}" class="mt-5 space-y-3" @if ($runtime['demo']) onsubmit="return false" @endif>
        <input type="hidden" name="step" value="{{ $runtime['step'] }}" />

        <label for="gym-code" class="sr-only">{{ __('Code') }}</label>
        <input
            id="gym-code"
            name="code"
            type="text"
            inputmode="{{ $obstacle['numeric'] ? 'numeric' : 'text' }}"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="{{ $obstacle['length'] + 4 }}"
            placeholder="{{ str_repeat('•', $obstacle['length']) }}"
            autofocus
            required
            class="block w-full rounded-lg border border-line bg-white px-4 py-3 text-center font-mono text-2xl tracking-[0.4em] uppercase outline-none focus:border-coral-400 focus:ring-2 focus:ring-coral-400/30 dark:border-zinc-700 dark:bg-zinc-950"
        />

        <button type="submit" class="w-full rounded-lg bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-ink/90 dark:bg-coral-500 dark:text-ink dark:hover:bg-coral-400">
            {{ __('Continue') }}
        </button>
    </form>

    @if ($runtime['demo'])
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Preview only. Use “Try it” to walk the whole routine.') }}</p>
    @else
        <form method="POST" action="{{ $runtime['attemptUrl'] }}" class="mt-3">
            <input type="hidden" name="step" value="{{ $runtime['step'] }}" />
            <input type="hidden" name="refresh" value="1" />
            <button type="submit" class="text-xs text-zinc-500 underline-offset-2 hover:underline dark:text-zinc-400">{{ __('Can’t read it? Get a new code') }}</button>
        </form>
    @endif
</div>
