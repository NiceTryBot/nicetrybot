<?php

use App\Enums\DomainStatus;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use NiceTry\Rewrites\Enums\RewriteContent;
use NiceTry\Rewrites\Models\ResponseRewrite;

new #[Title('Rewrites')] class extends Component
{
    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $find = '';

    public string $replace = '';

    public bool $ignoreCase = false;

    public int $content = RewriteContent::Html->value;

    public bool $inHeaders = false;

    public bool $enabled = true;

    /**
     * The one domain the rewrite covers, or '' for every domain of the team.
     */
    public int|string $domainId = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [ResponseRewrite::class, $this->team]);
    }

    /**
     * @return Collection<int, ResponseRewrite>
     */
    #[Computed]
    public function rewrites(): Collection
    {
        return $this->team->responseRewrites()->with('domain:id,hostname,status')->orderBy('id')->get();
    }

    /**
     * The team's domains, to pick from.
     *
     * @return Collection<int, \App\Models\Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname', 'status']);
    }

    /**
     * Open the editor for a new rewrite.
     */
    public function create(): void
    {
        Gate::authorize('create', [ResponseRewrite::class, $this->team]);

        $this->resetValidation();
        $this->reset(['editingId', 'find', 'replace', 'ignoreCase', 'content', 'inHeaders', 'enabled', 'domainId']);

        Flux::modal('rewrite-editor')->show();
    }

    /**
     * Open the editor on one of the team's rewrites.
     */
    public function edit(int $rewriteId): void
    {
        $rewrite = $this->team->responseRewrites()->findOrFail($rewriteId);
        Gate::authorize('update', $rewrite);

        $this->resetValidation();
        $this->editingId = $rewrite->id;
        $this->find = $rewrite->find;
        $this->replace = $rewrite->replace;
        $this->ignoreCase = $rewrite->ignore_case;
        $this->content = $rewrite->content->value;
        $this->inHeaders = $rewrite->in_headers;
        $this->enabled = $rewrite->enabled;
        $this->domainId = $rewrite->domain_id ?? '';

        Flux::modal('rewrite-editor')->show();
    }

    /**
     * Save the rewrite being edited (or the new one). The edge has it within a few seconds.
     */
    public function save(): void
    {
        $rewrite = $this->editingId === null ? null : $this->team->responseRewrites()->findOrFail($this->editingId);
        $rewrite === null ? Gate::authorize('create', [ResponseRewrite::class, $this->team]) : Gate::authorize('update', $rewrite);

        ['rules' => $rules, 'messages' => $messages] = ResponseRewrite::editorRules();
        $this->validate([
            ...$rules,
            'domainId' => ['nullable', Rule::exists('domains', 'id')->where('team_id', $this->team->id)],
        ], $messages);

        $attributes = [
            'find' => $this->find,
            'replace' => $this->replace,
            'ignore_case' => $this->ignoreCase,
            'content' => RewriteContent::from($this->content),
            'in_headers' => $this->inHeaders,
            'enabled' => $this->enabled,
            'domain_id' => $this->domainId === '' ? null : (int) $this->domainId,
        ];

        $rewrite = $rewrite === null ? $this->team->responseRewrites()->create($attributes) : tap($rewrite)->update($attributes);

        unset($this->rewrites);
        Flux::modal('rewrite-editor')->close();
        Flux::toast(variant: 'success', text: $rewrite->enabled ? __('Saved. Your edges have it within 10 seconds.') : __('Saved, switched off.'));
    }

    /**
     * Switch a rewrite on or off.
     */
    public function toggle(int $rewriteId): void
    {
        $rewrite = $this->team->responseRewrites()->findOrFail($rewriteId);
        Gate::authorize('update', $rewrite);

        $rewrite->update(['enabled' => ! $rewrite->enabled]);
        unset($this->rewrites);
    }

    public function confirmDelete(int $rewriteId): void
    {
        $rewrite = $this->team->responseRewrites()->findOrFail($rewriteId);
        Gate::authorize('delete', $rewrite);

        $this->deletingId = $rewrite->id;
        Flux::modal('delete-rewrite')->show();
    }

    /**
     * Delete the rewrite.
     */
    public function delete(): void
    {
        $rewrite = $this->team->responseRewrites()->findOrFail($this->deletingId);
        Gate::authorize('delete', $rewrite);

        $rewrite->delete();
        $this->deletingId = null;
        unset($this->rewrites);

        Flux::modal('delete-rewrite')->close();
        Flux::toast(text: __('Rewrite deleted.'));
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Change what visitors see')"
        :title="__('Rewrites')"
        :description="__('The edge changes text in what your origins send before it reaches visitors: every “foo” becomes “bar”, on all your domains or on one. Your origins stay as they are.')"
    >
        <x-slot:actions>
            @if ($this->canManage)
                <flux:button size="sm" variant="primary" icon="plus" wire:click="create" data-test="new-rewrite">{{ __('New rewrite') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-ntb.page-heading>

    <div class="space-y-4">
        @forelse ($this->rewrites as $rewrite)
            <x-ntb.panel wire:key="rewrite-{{ $rewrite->id }}" data-test="rewrite">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 font-mono text-sm">
                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 break-all dark:bg-zinc-800">{{ $rewrite->find }}</code>
                            <flux:icon.arrow-right class="size-4 shrink-0 text-zinc-400" />
                            @if ($rewrite->replace === '')
                                <span class="font-sans text-zinc-500 italic dark:text-zinc-400">{{ __('removed') }}</span>
                            @else
                                <code class="rounded bg-zinc-100 px-1.5 py-0.5 break-all dark:bg-zinc-800">{{ $rewrite->replace }}</code>
                            @endif
                            @unless ($rewrite->enabled)
                                <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                            @endunless
                        </div>

                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-xs text-zinc-500 sm:grid-cols-[auto_1fr] dark:text-zinc-400">
                            <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Where') }}</dt>
                            <dd data-test="rewrite-where">
                                @if ($rewrite->isTeamWide())
                                    {{ __('Every domain') }}
                                @else
                                    {{ $rewrite->domain?->hostname }}
                                    @if ($rewrite->domain?->status !== DomainStatus::Active)
                                        ({{ __('not active yet') }})
                                    @endif
                                @endif
                            </dd>
                            <dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('In') }}</dt>
                            <dd>{{ $rewrite->content->label() }}{{ $rewrite->in_headers ? ' · '.__('headers') : '' }}{{ $rewrite->ignore_case ? ' · '.__('any case') : '' }}</dd>
                        </dl>
                    </div>

                    @if ($this->canManage)
                        <div class="flex items-center gap-2">
                            <flux:switch :checked="$rewrite->enabled" wire:click="toggle({{ $rewrite->id }})" :aria-label="__('On')" data-test="rewrite-toggle" />
                            <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $rewrite->id }})" data-test="rewrite-edit">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $rewrite->id }})" :aria-label="__('Delete')" />
                        </div>
                    @endif
                </div>
            </x-ntb.panel>
        @empty
            <x-ntb.panel>
                <x-ntb.empty-state :heading="__('No rewrites yet.')">
                    {{ __('Change a word, a link or a snippet on every page your sites send, without touching their code.') }}
                    @if ($this->canManage)
                        <x-slot:actions>
                            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New rewrite') }}</flux:button>
                        </x-slot:actions>
                    @endif
                </x-ntb.empty-state>
            </x-ntb.panel>
        @endforelse
    </div>

    <flux:modal name="rewrite-editor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? __('New rewrite') : __('Edit rewrite') }}</flux:heading>
                <flux:subheading>{{ __('Plain text, matched exactly as written. Responses over 4 MB pass untouched.') }}</flux:subheading>
            </div>

            <flux:textarea wire:model="find" :label="__('Find')" rows="2" required maxlength="1000" placeholder="foo" data-test="rewrite-find" />
            <flux:textarea wire:model="replace" :label="__('Replace with')" :description="__('Leave it empty to remove the text.')" rows="2" maxlength="10000" placeholder="bar" data-test="rewrite-replace" />

            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="ignoreCase" :label="__('Ignore case')" :description="__('Foo and FOO too.')" />
                <flux:switch wire:model="enabled" :label="__('On')" />
            </div>

            <flux:radio.group wire:model="content" :label="__('In')">
                @foreach (RewriteContent::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" :description="$case->description()" />
                @endforeach
            </flux:radio.group>

            <flux:checkbox wire:model="inHeaders" :label="__('Response headers too')" :description="__('Cookies’ Domain, redirects’ Location, CORS origins and the like, on every response. Not lengths or types.')" data-test="rewrite-headers" />

            <flux:select wire:model="domainId" :label="__('Where')" data-test="rewrite-domain">
                <flux:select.option value="">{{ __('Every domain') }}</flux:select.option>
                @foreach ($this->domains as $domain)
                    <flux:select.option :value="$domain->id">{{ $domain->hostname }}{{ $domain->status === DomainStatus::Active ? '' : ' ('.__('not active yet').')' }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('domainId') <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="rewrite-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-rewrite" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this rewrite?') }}</flux:heading>
                <flux:subheading>{{ __('Responses go back to what your origin sends within 10 seconds.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
