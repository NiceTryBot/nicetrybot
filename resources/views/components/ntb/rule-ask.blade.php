@props([
    'hasKey' => false,
    'isEditing' => false,
    'editingId' => null,
    'askError' => null,
])

{{-- Ask: a rule in plain English, written into the editor by Claude. --}}
<div {{ $attributes }} data-test="rule-ask">
    @if ($hasKey)
        <form wire:submit="ask" class="flex items-center gap-2 rounded-xl border border-line bg-white py-1 ps-3 pe-1 shadow-xs transition focus-within:border-coral-400 focus-within:ring-4 focus-within:ring-coral-500/10 dark:border-zinc-700 dark:bg-zinc-900 dark:focus-within:border-coral-400/70">
            <span class="shrink-0 text-coral-500" wire:loading.class="animate-pulse" wire:target="ask">
                <flux:icon.sparkles class="size-5" />
            </span>
            <input
                type="text"
                wire:model="question"
                x-on:keydown.escape.stop="$el.blur()"
                autocomplete="off"
                spellcheck="false"
                enterkeyhint="go"
                placeholder="{{ $isEditing && ! $editingId ? __('Change the draft, like “make it last a week”') : __('Describe a rule, like “send everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym”') }}"
                class="min-w-0 flex-1 bg-transparent py-1.5 text-[15px] text-ink outline-none placeholder:text-zinc-400 dark:text-white dark:placeholder:text-zinc-500"
                aria-label="{{ __('Describe a rule') }}"
                data-test="rule-question"
            />
            <flux:button type="submit" size="sm" variant="primary" class="shrink-0" data-test="rule-ask-button">
                <span wire:loading.remove wire:target="ask">{{ $isEditing && ! $editingId ? __('Change it') : __('Write rule') }}</span>
                <span wire:loading wire:target="ask">{{ __('Thinking…') }}</span>
            </flux:button>
        </form>

        <div wire:loading.flex wire:target="ask" class="mt-2 hidden items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"><span class="block h-full w-1/2 animate-pulse rounded-full bg-coral-400"></span></span>
            {{ __('Claude is writing the rule…') }}
        </div>

        @if ($askError)
            <p class="mt-2 flex gap-2 text-sm text-rose-700 dark:text-rose-300" wire:loading.remove wire:target="ask" data-test="rule-ask-error">
                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0" />
                <span>{{ $askError }}</span>
            </p>
        @endif
    @else
        <div class="flex items-start gap-3 rounded-lg border border-dashed border-coral-300 bg-coral-50/60 px-3 py-2.5 text-sm dark:border-coral-500/30 dark:bg-coral-500/5" data-test="rule-ask-connect">
            <flux:icon.sparkles variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-500" />
            <p class="text-zinc-700 dark:text-zinc-200">
                {{ __('Describe a rule in plain English, like “send everyone who hits phpmyadmin.php to the PhpMyAdmin Gym”.') }}
                <a href="{{ route('ai.edit') }}" wire:navigate class="font-medium text-coral-700 underline underline-offset-2 dark:text-coral-300">{{ __('Add your Anthropic API key') }}</a>
                {{ __('and Claude writes the rule and sets up its gym routine.') }}
            </p>
        </div>
    @endif
</div>
