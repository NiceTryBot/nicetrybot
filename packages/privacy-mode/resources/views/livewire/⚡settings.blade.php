<?php

use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use NiceTry\PrivacyMode\Models\PrivacyWord;

new #[Title('Privacy settings')] class extends Component {
    /**
     * A new word to hide, until it's added.
     */
    public string $word = '';

    /**
     * What the new word shows as; blank shows a dot per letter.
     */
    public string $replacement = '';

    /**
     * Hide another word everywhere privacy mode is on.
     */
    public function addWord(): void
    {
        $this->word = mb_strtolower(trim($this->word));
        $this->replacement = trim($this->replacement);

        $this->validate([
            'word' => ['required', 'string', 'min:2', 'max:100', Rule::unique('privacy_words', 'word')],
            'replacement' => ['nullable', 'string', 'max:100'],
        ], [
            'word.unique' => __('That word is already hidden.'),
        ], ['word' => __('word'), 'replacement' => __('stand-in')]);

        PrivacyWord::query()->create([
            'word' => $this->word,
            'replacement' => $this->replacement ?: null,
        ]);

        $this->reset('word', 'replacement');
        unset($this->words);

        $this->dispatch('privacy-words-changed', words: PrivacyWord::map());

        Flux::toast(variant: 'success', text: __('Privacy mode now hides that word too.'));
    }

    /**
     * Stop hiding a word.
     */
    public function removeWord(int $id): void
    {
        PrivacyWord::query()->whereKey($id)->first()?->delete();

        unset($this->words);

        $this->dispatch('privacy-words-changed', words: PrivacyWord::map());
    }

    /**
     * @return Collection<int, PrivacyWord>
     */
    #[Computed]
    public function words(): Collection
    {
        return PrivacyWord::query()->orderBy('word')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Privacy settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Privacy')" :subheading="__('Words privacy mode hides on every page, for everyone, alongside your domains')">
        <form wire:submit="addWord" class="flex flex-wrap items-end gap-2" data-test="privacy-word-form">
            <flux:input wire:model="word" :label="__('Word')" placeholder="acme" autocomplete="off" spellcheck="false" class="min-w-36 flex-1" data-test="privacy-word-input" />
            <flux:input wire:model="replacement" :label="__('Shows as')" :badge="__('Optional')" placeholder="•••••" autocomplete="off" spellcheck="false" class="min-w-36 flex-1" data-test="privacy-replacement-input" />
            <flux:button type="submit" variant="primary" icon="plus" data-test="add-privacy-word">{{ __('Hide') }}</flux:button>
        </form>

        <flux:text class="mt-2 text-xs">
            {{ __('Matched in any case, anywhere it appears, even inside a longer word or path: “acme” also hides /ast_acmelogo.png. Short words like “sign” hide inside “design” too.') }}
        </flux:text>

        <div class="mt-6 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="privacy-words">
            @forelse ($this->words as $privacyWord)
                <div class="flex items-center gap-3 px-4 py-2.5 text-sm" wire:key="privacy-word-{{ $privacyWord->id }}">
                    <span class="min-w-0 flex-1 truncate font-mono text-ink dark:text-white">{{ $privacyWord->word }}</span>
                    <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 text-zinc-400" />
                    <span class="min-w-0 flex-1 truncate font-mono text-zinc-500 dark:text-zinc-400">{{ $privacyWord->standIn() }}</span>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeWord({{ $privacyWord->id }})" :aria-label="__('Stop hiding')" :tooltip="__('Stop hiding')" data-test="remove-privacy-word" />
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No words yet. Privacy mode hides just your domains and origins.') }}
                </div>
            @endforelse
        </div>

        <flux:text class="mt-4 text-xs">
            {{ __('With privacy mode on, this list hides itself too, so it’s safe to open while sharing your screen. Toggle privacy mode with the button at the top right, or ⌘/ (Ctrl / on Windows).') }}
        </flux:text>
    </x-pages::settings.layout>
</section>
