<?php

use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Support\AnthropicClient;
use App\Support\AnthropicException;
use App\Support\AnthropicWorkspaceRequired;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Artificial Intelligence settings')] class extends Component {
    /**
     * A new Anthropic API key, until it's saved.
     */
    public string $apiKey = '';

    /**
     * The workspace an organization-wide key bills to. Keys made inside a workspace don't need one.
     */
    public string $workspaceId = '';

    /**
     * Whether the workspace field shows: asked for, or needed by the key Anthropic just saw.
     */
    public bool $needsWorkspace = false;

    /**
     * Whether the saved key is being replaced, which shows the form again.
     */
    public bool $replacing = false;

    /**
     * Show the form again to replace the saved key, keeping its workspace.
     */
    public function replaceAnthropicKey(): void
    {
        $this->workspaceId = (string) $this->anthropicKey?->workspace_id;
        $this->needsWorkspace = $this->workspaceId !== '';
        $this->replacing = true;
    }

    /**
     * Save the user's Anthropic API key, once Anthropic says it works.
     */
    public function saveAnthropicKey(AnthropicClient $anthropic): void
    {
        $this->apiKey = trim($this->apiKey);
        $this->workspaceId = trim($this->workspaceId);

        $this->validate([
            'apiKey' => ['required', 'string', 'max:255', function (string $attribute, string $value, Closure $fail) {
                if (! AiProvider::Anthropic->accepts($value)) {
                    $fail(__('That doesn’t look like an Anthropic API key. They start with sk-ant-.'));
                }
            }],
            'workspaceId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'workspaceId.regex' => __('A workspace ID is letters, numbers, dashes and underscores, like wrkspc_01…'),
        ], ['apiKey' => __('API key'), 'workspaceId' => __('workspace ID')]);

        try {
            $accepted = $anthropic->accepts($this->apiKey, $this->workspaceId ?: null);
        } catch (AnthropicWorkspaceRequired) {
            $this->needsWorkspace = true;
            $this->addError('workspaceId', __('This key belongs to your whole Anthropic organization, so Anthropic needs to know which workspace to bill. Paste that workspace’s ID, or create a key inside a workspace instead.'));

            return;
        } catch (AnthropicException $exception) {
            $this->addError('apiKey', $exception->getMessage());

            return;
        }

        if (! $accepted) {
            $this->addError('apiKey', __('Anthropic didn’t accept that key. Check that it’s active and copied whole.'));

            return;
        }

        Auth::user()->aiCredentials()->updateOrCreate(['provider' => AiProvider::Anthropic], [
            'secret' => $this->apiKey,
            'hint' => AiCredential::hintFor($this->apiKey),
            'workspace_id' => $this->workspaceId ?: null,
            'last_used_at' => null,
        ]);

        $this->reset('apiKey', 'workspaceId', 'needsWorkspace', 'replacing');
        unset($this->anthropicKey);

        Flux::toast(variant: 'success', text: __('Key saved. Activity search now understands plain English.'));
    }

    /**
     * Forget the user's Anthropic API key.
     */
    public function removeAnthropicKey(): void
    {
        Auth::user()->aiCredentials()->where('provider', AiProvider::Anthropic)->delete();

        $this->reset('apiKey', 'workspaceId', 'needsWorkspace', 'replacing');
        unset($this->anthropicKey);

        Flux::toast(text: __('Anthropic key removed.'));
    }

    #[Computed]
    public function anthropicKey(): ?AiCredential
    {
        return Auth::user()->aiCredential(AiProvider::Anthropic);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Artificial Intelligence settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Artificial Intelligence')" :subheading="__('Connect Claude to search Activity in plain English')">
        @php($anthropicKey = $this->anthropicKey)

        <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="anthropic-key">
            <div class="flex items-start gap-4 p-5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-coral-50 text-coral-600 dark:bg-coral-500/15 dark:text-coral-300">
                    <flux:icon.sparkles class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium tracking-tight">{{ __('Anthropic API key') }}</p>
                        @if ($anthropicKey)
                            <flux:badge size="sm" color="emerald" icon="check-circle">{{ __('Connected') }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('Not connected') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="mt-1">
                        {{ __('Type a question like “404s from hosting networks this week” into the Activity search and Claude writes the WHERE for it, using your key. Anthropic bills those requests to your account.') }}
                    </flux:text>
                    <flux:text class="mt-2 text-xs" data-test="ai-disclosure">
                        {{ __('Off until you add a key. Each question goes to Anthropic with the search or rule you’re editing and your team’s hostnames; request records aren’t sent, but anything you type into the question is, so leave out IP addresses you don’t want to share. This is the only feature that sends anything outside your servers.') }}
                    </flux:text>
                </div>
            </div>

            @if ($anthropicKey && ! $replacing)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="min-w-0 text-sm">
                        <span class="font-mono text-ink dark:text-white" data-test="anthropic-key-hint">{{ $anthropicKey->hint }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Added :time', ['time' => $anthropicKey->created_at->diffForHumans()]) }}
                            <span class="mx-1 opacity-50">/</span>
                            {{ $anthropicKey->last_used_at ? __('Last used :time', ['time' => $anthropicKey->last_used_at->diffForHumans()]) : __('Not used yet') }}
                            @if ($anthropicKey->workspace_id)
                                <span class="mx-1 opacity-50">/</span>
                                {{ __('Workspace') }} <span class="font-mono" data-test="anthropic-workspace">{{ $anthropicKey->workspace_id }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" wire:click="replaceAnthropicKey" data-test="replace-anthropic-key">{{ __('Replace') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeAnthropicKey" wire:confirm="{{ __('Remove your Anthropic key? Activity search goes back to matching words.') }}" data-test="remove-anthropic-key">{{ __('Remove') }}</flux:button>
                    </div>
                </div>
            @else
                <form wire:submit="saveAnthropicKey" class="space-y-3 border-t border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <flux:input
                        wire:model="apiKey"
                        type="password"
                        viewable
                        :label="__('API key')"
                        placeholder="sk-ant-…"
                        autocomplete="off"
                        spellcheck="false"
                        data-test="anthropic-key-input"
                    />
                    <flux:text class="text-xs">
                        {{ __('Create one in the') }}
                        <flux:link href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" class="text-xs">{{ __('Anthropic Console') }}</flux:link>
                        {{ __('under API keys. It’s checked with Anthropic before it’s saved.') }}
                    </flux:text>
                    @if ($needsWorkspace)
                        <flux:input
                            wire:model="workspaceId"
                            :label="__('Workspace ID')"
                            :badge="__('Optional')"
                            :description="__('For a key made for your whole organization: the workspace whose limits and billing Claude’s searches count against. It’s on the workspace’s page in the Console, under Settings → Workspaces.')"
                            placeholder="wrkspc_01…"
                            autocomplete="off"
                            spellcheck="false"
                            data-test="anthropic-workspace-input"
                        />
                    @else
                        <button type="button" wire:click="$set('needsWorkspace', true)" class="text-xs text-zinc-500 underline decoration-dotted underline-offset-2 hover:text-ink dark:text-zinc-400 dark:hover:text-white" data-test="add-anthropic-workspace">
                            {{ __('Key for your whole organization? Add its workspace ID') }}
                        </button>
                    @endif
                    <div class="flex items-center gap-2 pt-1">
                        <flux:button type="submit" variant="primary" size="sm" data-test="save-anthropic-key">
                            <span wire:loading.remove wire:target="saveAnthropicKey">{{ __('Save key') }}</span>
                            <span wire:loading wire:target="saveAnthropicKey">{{ __('Checking with Anthropic…') }}</span>
                        </flux:button>
                        @if ($anthropicKey)
                            <flux:button size="sm" variant="ghost" wire:click="$set('replacing', false)">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
                </form>
            @endif
        </div>

        <div class="mt-8">
            <flux:heading size="sm">{{ __('What Claude sees') }}</flux:heading>
            <ul class="mt-3 space-y-2.5 text-sm text-zinc-600 dark:text-zinc-300">
                <li class="flex gap-2.5">
                    <flux:icon.chat-bubble-left-ellipsis variant="mini" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <span>{{ __('Your question, the search it refines, and your team’s hostnames, so you can say “the shop” instead of a domain.') }}</span>
                </li>
                <li class="flex gap-2.5">
                    <flux:icon.code-bracket variant="mini" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <span>{{ __('The rule language’s fields and grammar. Claude only writes the search; NiceTryBot parses it and runs it, the same as one you type.') }}</span>
                </li>
                <li class="flex gap-2.5">
                    <flux:icon.no-symbol variant="mini" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <span>{{ __('Never your request logs. Results stay on NiceTryBot.') }}</span>
                </li>
                <li class="flex gap-2.5">
                    <flux:icon.lock-closed variant="mini" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <span>{{ __('Your key is encrypted at rest, used only for your searches, and never shown in full again.') }}</span>
                </li>
            </ul>

            @if ($anthropicKey)
                <flux:button :href="route('activity.index')" wire:navigate size="sm" icon:trailing="arrow-right" class="mt-6">{{ __('Try it in Activity') }}</flux:button>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
