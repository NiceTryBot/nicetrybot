<?php

use App\Models\Domain;
use App\Support\PrivacyHostname;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Domain $domain;

    /**
     * Give the domain another made-up stand-in, and swap it in on the page at once.
     */
    public function shufflePrivacyHostname(): void
    {
        Gate::authorize('update', $this->domain);

        $this->domain->update(['privacy_hostname' => PrivacyHostname::generate()]);

        $this->dispatch('privacy-hostname-changed', hostname: $this->domain->hostname, privacyHostname: $this->domain->privacy_hostname);

        Flux::toast(text: __('In privacy mode this domain now shows as :hostname.', ['hostname' => $this->domain->privacy_hostname]));
    }
}; ?>

<x-ntb.panel :heading="__('Privacy mode')" :subheading="__('Screen sharing? Turn on privacy mode and this domain shows as its stand-in everywhere in NiceTryBot.')">
    <div class="flex items-end gap-2" data-privacy-skip>
        <x-ntb.copy-field class="flex-1" :label="__('Shows as')" :value="$domain->privacy_hostname" wire:key="privacy-hostname-{{ $domain->privacy_hostname }}" data-test="privacy-hostname" />

        @can('update', $domain)
            <flux:button size="sm" icon="arrow-path" wire:click="shufflePrivacyHostname" :aria-label="__('Pick another stand-in')" :tooltip="__('Pick another')" data-test="shuffle-privacy-hostname" />
        @endcan
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('Toggle it with the Privacy button at the top right, or press') }}
        <kbd class="rounded border border-line px-1 font-mono text-[0.65rem] dark:border-zinc-700">⌘/</kbd>
        {{ __('(Ctrl / on Windows).') }}
    </p>
</x-ntb.panel>
