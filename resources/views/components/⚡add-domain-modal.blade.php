<?php

use App\Actions\Domains\AddDomain;
use App\Enums\HostHeaderMode;
use App\Models\Domain;
use App\Rules\HeaderNames;
use App\Rules\Hostname;
use App\Rules\ReachableOrigin;
use App\Support\AddDomainWithAiPrompt;
use App\Support\HeaderName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public string $hostname = '';

    public string $originUrl = '';

    public string $hostHeaderMode = 'pass_through';

    public string $originHostHeader = '';

    public string $forwardedHostHeader = '';

    public bool $servesWww = true;

    /**
     * The prompt "Add with AI" copies, for a coding agent in the person's project.
     */
    public function aiPrompt(): string
    {
        return app(AddDomainWithAiPrompt::class)->render();
    }

    public function addDomain(AddDomain $addDomain): void
    {
        $team = Auth::user()->currentTeam;

        Gate::authorize('create', [Domain::class, $team]);

        $this->hostname = Hostname::normalize($this->hostname);

        $this->forwardedHostHeader = HeaderName::normalizeList($this->forwardedHostHeader);

        $validated = $this->validate([
            'hostname' => ['required', 'string', new Hostname, Rule::unique('domains', 'hostname')],
            'originUrl' => [
                'required',
                'string',
                'max:255',
                'url:http,https',
                new ReachableOrigin($this->hostname),
            ],
            'hostHeaderMode' => ['required', Rule::enum(HostHeaderMode::class)],
            'originHostHeader' => ['nullable', 'string', new Hostname],
            'forwardedHostHeader' => ['nullable', 'string', new HeaderNames],
            'servesWww' => ['boolean'],
        ], [
            'hostname.unique' => __('That domain is already protected by NiceTryBot.'),
        ]);

        $domain = $addDomain->handle($team, [
            'hostname' => $validated['hostname'],
            'origin_url' => $validated['originUrl'],
            'host_header_mode' => $validated['hostHeaderMode'],
            'origin_host_header' => $validated['originHostHeader'] ?? null,
            'forwarded_host_header' => $validated['forwardedHostHeader'] ?? null,
            'serves_www' => $validated['servesWww'],
        ]);

        $this->dispatch('close-modal', name: 'add-domain');

        $this->reset();

        Flux::toast(variant: 'success', text: __(':hostname added. Verify it to go live.', ['hostname' => $domain->hostname]));

        $this->redirectRoute('domains.show', ['domain' => $domain], navigate: true);
    }
}; ?>

<flux:modal name="add-domain" :show="$errors->isNotEmpty()" focusable class="md:w-xl">
    <form wire:submit="addDomain" class="space-y-6">
        <div>
            <p class="mb-2 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('New domain origin') }}</p>
            <flux:heading size="lg">{{ __('Put a domain behind NiceTryBot') }}</flux:heading>
            <flux:subheading>{{ __('Tell us where visitors arrive and where your app lives. Nothing changes for your visitors until you verify the domain and point DNS at the edge.') }}</flux:subheading>
        </div>

        <flux:input
            wire:model="hostname"
            :label="__('Domain')"
            :description="__('The hostname visitors type, without https:// or a path.')"
            placeholder="claims.example.com"
            autocomplete="off"
            spellcheck="false"
            required
            autofocus
            data-test="add-domain-hostname"
        />

        <flux:checkbox
            wire:model="servesWww"
            :label="__('Serve www. too')"
            :description="__('Visitors to www.<domain> get the same site, counted here. Turn it off for a subdomain nobody types www. in front of.')"
            data-test="add-domain-serves-www"
        />

        <flux:input
            wire:model="originUrl"
            :label="__('Origin URL')"
            :description="__('Where the edge forwards human traffic. Bots never reach it when you enforce.')"
            placeholder="https://app.example.net"
            icon="server-stack"
            autocomplete="off"
            spellcheck="false"
            required
            data-test="add-domain-origin"
        />

        <flux:radio.group wire:model.live="hostHeaderMode" :label="__('Host header')" variant="cards" class="flex-col">
            @foreach (HostHeaderMode::cases() as $mode)
                <flux:radio :value="$mode->value" :label="$mode->label()" :description="$mode->description()" />
            @endforeach
        </flux:radio.group>

        @if ($hostHeaderMode === HostHeaderMode::Rewrite->value)
            <flux:input
                wire:model="originHostHeader"
                :label="__('Host header sent to the origin')"
                :description="__('Leave blank to use the origin URL\'s host.')"
                placeholder="sites.example.net"
                autocomplete="off"
            />
        @endif

        <flux:input
            wire:model="forwardedHostHeader"
            :label="__('Forward the visitor\'s host in a header (optional)')"
            :description="__('Lets a shared origin know which site was requested. Separate several with commas.')"
            placeholder="User-Custom-Domain"
            autocomplete="off"
            spellcheck="false"
        />

        <div x-data="{ prompt: @js($this->aiPrompt()), copied: false }" class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    variant="ghost"
                    x-on:click="navigator.clipboard.writeText(prompt); copied = true"
                    data-test="add-domain-with-ai"
                >
                    {{ __('✨ Add with AI') }}
                </flux:button>

                <div class="ms-auto flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" icon="plus" data-test="add-domain-submit">
                        {{ __('Add domain') }}
                    </flux:button>
                </div>
            </div>

            <flux:callout x-show="copied" x-cloak variant="success" icon="clipboard-document-check" data-test="add-domain-with-ai-copied">
                <flux:callout.heading>{{ __('Prompt copied') }}</flux:callout.heading>
                <flux:callout.text>
                    <p>{{ __('Paste it into Claude Code, Cursor or another coding agent, opened in your project. The agent:') }}</p>
                    <ol class="mt-2 list-decimal space-y-1 ps-5" data-test="add-domain-with-ai-steps">
                        <li>{{ __('Asks you to connect it with an API token, which an administrator makes in Admin → API tokens and you add in your own terminal.') }}</li>
                        <li>{{ __('Works out your public hostname, an origin hostname and the endpoints machines call, and checks them with you.') }}</li>
                        <li>{{ __('Serves your app at the origin hostname.') }}</li>
                        <li>{{ __('Adds the domain here and verifies it.') }}</li>
                        <li>{{ __('Makes the origin refuse requests that skip the edge.') }}</li>
                        <li>{{ __('Tests it, then stops so you can try it before any DNS changes.') }}</li>
                        <li>{{ __('Points DNS at the edge only when you say so.') }}</li>
                    </ol>
                </flux:callout.text>
            </flux:callout>
        </div>
    </form>
</flux:modal>
