<?php

use App\Data\LabelDirectory;
use App\Enums\LabelKind;
use App\Models\ClientLabel;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $kind = 'ip';

    public string $value = '';

    public string $name = '';

    /**
     * Whether the value is the team's own traffic, hidden from Activity by default.
     */
    public bool $isMine = false;

    public ?int $existingId = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Open the modal for one value, showing the label it already has, if any.
     */
    #[On('label-value')]
    public function open(string $kind, string $value): void
    {
        Gate::authorize('create', [ClientLabel::class, $this->team]);

        $labelKind = LabelKind::tryFrom($kind);

        abort_unless($labelKind !== null && $labelKind->accepts($value), 422);

        $existing = app(LabelDirectory::class)->labelFor($this->team->id, $labelKind, $value);

        $this->kind = $labelKind->value;
        $this->value = $value;
        $this->existingId = $existing?->id;
        $this->name = $existing->name ?? '';
        $this->isMine = $existing->is_mine ?? false;

        $this->resetErrorBag();
        Flux::modal('label-value')->show();
    }

    public function save(): void
    {
        $labelKind = LabelKind::from($this->kind);

        abort_unless($labelKind->accepts($this->value), 422);

        $existing = $this->team->clientLabels()->where('kind', $labelKind)->where('value_hash', md5($this->value))->first();

        $existing === null
            ? Gate::authorize('create', [ClientLabel::class, $this->team])
            : Gate::authorize('update', $existing);

        $this->validate(['name' => ['required', 'string', 'max:60']], ['name.required' => __('Give it a name.')]);

        $existing === null
            ? $this->team->clientLabels()->create(['kind' => $labelKind, 'value' => $this->value, 'name' => trim($this->name), 'is_mine' => $this->isMine])
            : $existing->update(['name' => trim($this->name), 'is_mine' => $this->isMine]);

        $this->finish(__('Named :name.', ['name' => trim($this->name)]));
    }

    public function remove(): void
    {
        $existing = $this->team->clientLabels()->findOrFail($this->existingId);

        Gate::authorize('update', $existing);

        $existing->delete();

        $this->finish(__('Name removed.'));
    }

    private function finish(string $message): void
    {
        Flux::modal('label-value')->close();
        Flux::toast(variant: 'success', text: $message);

        $this->dispatch('label-changed');
    }
}; ?>

<flux:modal name="label-value" class="md:w-lg">
    <form wire:submit="save" class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __($existingId ? 'Rename this :kind' : 'Name this :kind', ['kind' => LabelKind::from($kind)->label()]) }}</flux:heading>
            <flux:subheading>{{ __('The name shows in its place everywhere it appears, for your whole team.') }}</flux:subheading>
        </div>

        <p class="rounded-lg bg-canvas px-3 py-2 font-mono text-xs break-all text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">{{ $value }}</p>

        <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. TechTies stuffer')" maxlength="60" autofocus data-test="label-name-input" />

        <flux:switch wire:model="isMine" :label="__('This is my traffic')" :description="__('Activity hides your own visits by default, so you see everyone else. One click on My traffic shows them again.')" data-test="label-is-mine" />

        <div class="flex items-center justify-between gap-2">
            <div>
                @if ($existingId)
                    <flux:button variant="ghost" icon="trash" wire:click="remove" data-test="remove-label">{{ __('Remove name') }}</flux:button>
                @endif
            </div>
            <div class="flex gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-label">{{ __('Save name') }}</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
