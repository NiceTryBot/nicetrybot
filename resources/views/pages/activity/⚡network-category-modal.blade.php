<?php

use App\Enums\NetworkCategory as Category;
use App\Models\Network;
use App\Models\NetworkCategory;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?int $asn = null;

    #[Locked]
    public ?string $networkName = null;

    /**
     * The category's slug, or empty for "Not set".
     */
    public string $category = '';

    /**
     * Open the modal for the network of the client picked on the Activity page, showing what it's under now.
     */
    #[On('network-category-edit')]
    public function open(int $asn): void
    {
        Gate::authorize('administer');

        abort_unless($asn > 0 && $asn <= 4294967295, 422);

        $this->asn = $asn;
        $this->networkName = Network::query()->whereKey($asn)->value('name');
        $this->category = NetworkCategory::query()->find($asn)?->category?->slug() ?? '';

        $this->resetErrorBag();
        Flux::modal('network-category')->show();
    }

    /**
     * Save the correction for the whole installation. It outlives any reload of the data, and "Not set"
     * has to be saved too, or the next reload would bring the data's category back.
     */
    public function save(): void
    {
        Gate::authorize('administer');

        abort_if($this->asn === null, 422);

        $this->validate([
            'category' => ['nullable', 'string', Rule::in(array_map(fn (Category $category) => $category->slug(), Category::cases()))],
        ]);

        $chosen = $this->category === '' ? null : Category::fromSlug($this->category);

        NetworkCategory::query()->updateOrCreate(['asn' => $this->asn], ['category' => $chosen, 'is_override' => true]);

        Flux::modal('network-category')->close();
        Flux::toast(variant: 'success', text: __('AS:asn is now :category, for every team.', ['asn' => $this->asn, 'category' => $chosen?->label() ?? __('not set')]));

        $this->dispatch('network-category-changed');
    }
}; ?>

<flux:modal name="network-category" class="md:w-md">
    <form wire:submit="save" class="space-y-5" data-test="network-category-form">
        <div>
            <flux:heading size="lg">{{ __('Network category') }}</flux:heading>
            <flux:subheading>{{ __('This corrects the network for the whole installation, every team included, and later updates of the data leave it alone.') }}</flux:subheading>
        </div>

        <p class="rounded-lg bg-canvas px-3 py-2 font-mono text-xs break-all text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">{{ trim('AS'.$asn.' '.$networkName) }}</p>

        <flux:select wire:model="category" :label="__('Category')" data-test="network-category-select">
            @foreach (Category::cases() as $option)
                <flux:select.option :value="$option->slug()">{{ $option->label() }}</flux:select.option>
            @endforeach
            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
        </flux:select>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary" data-test="network-category-save">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
