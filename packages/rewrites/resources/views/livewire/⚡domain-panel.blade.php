<?php

use App\Models\Domain;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use NiceTry\Rewrites\Enums\RewriteContent;
use NiceTry\Rewrites\Models\ResponseRewrite;

new class extends Component
{
    #[Locked]
    public int $domainId;

    public ?int $editingId = null;

    public string $find = '';

    public string $replace = '';

    public bool $ignoreCase = false;

    public int $content = RewriteContent::Html->value;

    public bool $inHeaders = false;

    public bool $enabled = true;

    public function mount(Domain $domain): void
    {
        abort_unless($domain->team_id === $this->team->id, 404);

        $this->domainId = $domain->id;
    }

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
     * The rewrites on this domain: the team's for every domain first, then its own, oldest first, as
     * the edge applies them.
     *
     * @return Collection<int, ResponseRewrite>
     */
    #[Computed]
    public function rewrites(): Collection
    {
        return $this->team->responseRewrites()
            ->where(fn ($query) => $query->whereNull('domain_id')->orWhere('domain_id', $this->domainId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Open the editor for a new rewrite on this domain.
     */
    public function create(): void
    {
        Gate::authorize('create', [ResponseRewrite::class, $this->team]);

        $this->resetValidation();
        $this->reset(['editingId', 'find', 'replace', 'ignoreCase', 'content', 'inHeaders', 'enabled']);

        Flux::modal('domain-rewrite-editor')->show();
    }

    /**
     * Open the editor on one of this domain's own rewrites. The team's rewrites for every domain are
     * changed on the Rewrites page, where it's plain they reach every site.
     */
    public function edit(int $rewriteId): void
    {
        $rewrite = $this->ownRewrite($rewriteId);
        Gate::authorize('update', $rewrite);

        $this->resetValidation();
        $this->editingId = $rewrite->id;
        $this->find = $rewrite->find;
        $this->replace = $rewrite->replace;
        $this->ignoreCase = $rewrite->ignore_case;
        $this->content = $rewrite->content->value;
        $this->inHeaders = $rewrite->in_headers;
        $this->enabled = $rewrite->enabled;

        Flux::modal('domain-rewrite-editor')->show();
    }

    /**
     * Save the rewrite being edited (or the new one), on this domain. The edge has it within a few seconds.
     */
    public function save(): void
    {
        $rewrite = $this->editingId === null ? null : $this->ownRewrite($this->editingId);
        $rewrite === null ? Gate::authorize('create', [ResponseRewrite::class, $this->team]) : Gate::authorize('update', $rewrite);

        ['rules' => $rules, 'messages' => $messages] = ResponseRewrite::editorRules();
        $this->validate($rules, $messages);

        $attributes = [
            'domain_id' => $this->domainId,
            'find' => $this->find,
            'replace' => $this->replace,
            'ignore_case' => $this->ignoreCase,
            'content' => RewriteContent::from($this->content),
            'in_headers' => $this->inHeaders,
            'enabled' => $this->enabled,
        ];

        $rewrite = $rewrite === null ? $this->team->responseRewrites()->create($attributes) : tap($rewrite)->update($attributes);

        unset($this->rewrites);
        Flux::modal('domain-rewrite-editor')->close();
        Flux::toast(variant: 'success', text: $rewrite->enabled ? __('Saved. Your edges have it within 10 seconds.') : __('Saved, switched off.'));
    }

    /**
     * Switch one of this domain's own rewrites on or off.
     */
    public function toggle(int $rewriteId): void
    {
        $rewrite = $this->ownRewrite($rewriteId);
        Gate::authorize('update', $rewrite);

        $rewrite->update(['enabled' => ! $rewrite->enabled]);
        unset($this->rewrites);
    }

    /**
     * Delete one of this domain's own rewrites.
     */
    public function delete(int $rewriteId): void
    {
        $rewrite = $this->ownRewrite($rewriteId);
        Gate::authorize('delete', $rewrite);

        $rewrite->delete();
        unset($this->rewrites);

        Flux::toast(text: __('Rewrite deleted.'));
    }

    private function ownRewrite(int $rewriteId): ResponseRewrite
    {
        return $this->team->responseRewrites()->where('domain_id', $this->domainId)->findOrFail($rewriteId);
    }
}; ?>

<x-ntb.panel :heading="__('Rewrites')" :subheading="__('Text the edge changes in this site\'s responses before visitors see it.')" data-test="domain-rewrites">
    @if ($this->canManage)
        <x-slot name="actions">
            <flux:button size="sm" icon="plus" wire:click="create" data-test="domain-rewrite-new">{{ __('Add') }}</flux:button>
        </x-slot>
    @endif

    <div class="space-y-2">
        @forelse ($this->rewrites as $rewrite)
            <div wire:key="domain-rewrite-{{ $rewrite->id }}" class="flex items-start justify-between gap-3 rounded-lg bg-canvas px-3 py-2 dark:bg-zinc-800/60" data-test="domain-rewrite">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-1.5 font-mono text-xs">
                        <span class="break-all">{{ $rewrite->find }}</span>
                        <flux:icon.arrow-right variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                        @if ($rewrite->replace === '')
                            <span class="font-sans text-zinc-500 italic dark:text-zinc-400">{{ __('removed') }}</span>
                        @else
                            <span class="break-all">{{ $rewrite->replace }}</span>
                        @endif
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $rewrite->content->label() }}{{ $rewrite->in_headers ? ' · '.__('headers') : '' }}{{ $rewrite->ignore_case ? ' · '.__('any case') : '' }}
                        @if ($rewrite->isTeamWide())
                            <flux:badge size="sm">{{ __('Every domain') }}</flux:badge>
                        @endif
                        @unless ($rewrite->enabled)
                            <flux:badge size="sm">{{ __('Off') }}</flux:badge>
                        @endunless
                    </p>
                </div>

                @if ($this->canManage)
                    @if ($rewrite->isTeamWide())
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('rewrites.index')" wire:navigate :aria-label="__('Change on the Rewrites page')" />
                    @else
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:switch :checked="$rewrite->enabled" wire:click="toggle({{ $rewrite->id }})" :aria-label="__('On')" data-test="domain-rewrite-toggle" />
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $rewrite->id }})" :aria-label="__('Edit')" data-test="domain-rewrite-edit" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $rewrite->id }})" wire:confirm="{{ __('Delete this rewrite?') }}" :aria-label="__('Delete')" />
                        </div>
                    @endif
                @endif
            </div>
        @empty
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('None. Responses reach visitors as your origin sends them.') }}</p>
        @endforelse
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        <flux:link :href="route('rewrites.index')" wire:navigate>{{ __('All rewrites') }} →</flux:link>
    </p>

    <flux:modal name="domain-rewrite-editor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? __('New rewrite') : __('Edit rewrite') }}</flux:heading>
                <flux:subheading>{{ __('On this domain only. Plain text, matched exactly as written. Responses over 4 MB pass untouched.') }}</flux:subheading>
            </div>

            <flux:textarea wire:model="find" :label="__('Find')" rows="2" required maxlength="1000" placeholder="foo" data-test="domain-rewrite-find" />
            <flux:textarea wire:model="replace" :label="__('Replace with')" :description="__('Leave it empty to remove the text.')" rows="2" maxlength="10000" placeholder="bar" data-test="domain-rewrite-replace" />

            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="ignoreCase" :label="__('Ignore case')" :description="__('Foo and FOO too.')" />
                <flux:switch wire:model="enabled" :label="__('On')" />
            </div>

            <flux:radio.group wire:model="content" :label="__('In')">
                @foreach (RewriteContent::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" :description="$case->description()" />
                @endforeach
            </flux:radio.group>

            <flux:checkbox wire:model="inHeaders" :label="__('Response headers too')" :description="__('Cookies’ Domain, redirects’ Location, CORS origins and the like, on every response. Not lengths or types.')" data-test="domain-rewrite-headers" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="domain-rewrite-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-ntb.panel>
