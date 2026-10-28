{{-- Turns privacy mode on and off, beside the cog: every real domain on the page swaps for its made-up stand-in (see resources/js/privacy.js). --}}
<button
    type="button"
    x-data="{ on: window.privacyMode?.enabled ?? document.documentElement.classList.contains('privacy-mode'), shortcut: /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘/' : 'Ctrl /' }"
    x-on:privacy-mode-changed.window="on = $event.detail.enabled"
    x-on:click="window.privacyMode?.toggle()"
    x-bind:aria-pressed="on.toString()"
    x-bind:title="(on ? @js(__('Privacy mode is on: domains and IPs are hidden')) : @js(__('Hide domains and IPs for screen sharing'))) + ' (' + shortcut + ')'"
    class="group inline-flex h-8 items-center gap-1.5 rounded-lg border px-2 text-xs font-medium transition sm:px-2.5"
    x-bind:class="on
        ? 'border-coral-300 bg-coral-50 text-coral-700 hover:bg-coral-100 dark:border-coral-500/40 dark:bg-coral-500/10 dark:text-coral-300 dark:hover:bg-coral-500/20'
        : 'border-line bg-white text-zinc-500 hover:text-ink dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
    aria-label="{{ __('Privacy mode') }}"
    data-test="privacy-toggle"
>
    <flux:icon.eye-slash variant="micro" class="size-4" x-show="on" x-cloak />
    <flux:icon.eye variant="micro" class="size-4" x-show="! on" />
    <span class="hidden sm:inline">{{ __('Privacy') }}</span>
    <span class="hidden font-mono text-[0.65rem] sm:inline" x-text="on ? @js(__('on')) : @js(__('off'))"></span>
    <kbd class="hidden rounded border border-current/20 px-1 font-mono text-[0.6rem] opacity-70 lg:inline" x-text="shortcut"></kbd>
</button>
