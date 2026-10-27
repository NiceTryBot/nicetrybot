@props([
    'label',
    'value',
    'secret' => false,
])

{{-- Click anywhere on the field (or press Enter on it) to copy. The value lives in x-data on this plain
     element: @js() isn't compiled inside a Flux component's attributes. --}}
<div
    {{ $attributes->class('min-w-0') }}
    x-data="{
        value: @js($value),
        revealed: {{ $secret ? 'false' : 'true' }},
        copied: false,
        copy() {
            navigator.clipboard.writeText(this.value);
            this.copied = true;
            setTimeout(() => this.copied = false, 1500);
        },
    }"
>
    <p class="mb-1 font-mono text-[0.65rem] tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ $label }}</p>

    <div
        role="button"
        tabindex="0"
        x-on:click="copy()"
        x-on:keydown.enter.prevent="copy()"
        x-on:keydown.space.prevent="copy()"
        aria-label="{{ __('Copy :label', ['label' => $label]) }}"
        title="{{ __('Click to copy') }}"
        class="flex cursor-copy items-center gap-1 rounded-lg border border-line bg-canvas py-1 ps-3 pe-1 transition hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-accent dark:border-zinc-700 dark:bg-zinc-950 dark:hover:border-zinc-500"
        data-test="copy-field"
    >
        <code class="min-w-0 flex-1 truncate font-mono text-xs text-ink dark:text-zinc-100" x-show="revealed">{{ $value }}</code>
        <code class="min-w-0 flex-1 truncate font-mono text-xs text-zinc-400" x-show="! revealed" x-cloak>{{ str_repeat('•', 24) }}</code>

        <span class="shrink-0 pe-1 text-xs text-emerald-600 dark:text-emerald-400" x-show="copied" x-cloak x-transition>{{ __('Copied') }}</span>

        @if ($secret)
            <flux:button size="xs" variant="ghost" icon="eye" x-on:click.stop="revealed = ! revealed" :aria-label="__('Show or hide')" class="shrink-0" />
        @endif

        <flux:icon.clipboard-document variant="micro" class="me-1.5 shrink-0 text-zinc-400" />
    </div>
</div>
