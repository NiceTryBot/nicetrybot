<?php

use App\Addons\DomainLimit;
use App\Addons\InvalidLicenceKey;
use App\Models\DomainPack;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * A More domains pack's key, being pasted in.
     */
    public string $packKey = '';

    /**
     * @return Collection<int, DomainPack>
     */
    #[Computed]
    public function packs(): Collection
    {
        return DomainPack::query()->with('enteredBy')->orderBy('id')->get();
    }

    public function addPack(DomainLimit $limit): void
    {
        Gate::authorize('administer');

        $this->validate(['packKey' => ['required', 'string', 'max:4000']]);

        try {
            $pack = $limit->enter($this->packKey, auth()->user());
        } catch (InvalidLicenceKey $e) {
            throw ValidationException::withMessages(['packKey' => $e->getMessage()]);
        }

        $this->reset('packKey');
        unset($this->packs);

        Flux::toast(
            variant: $pack->isValid() ? 'success' : 'danger',
            text: $pack->isValid()
                ? __('Pack added: this installation now protects up to :allowed domains.', ['allowed' => $limit->allowed()])
                : __('The marketplace says that pack was refunded, so it doesn’t add any domains.'),
        );
    }

    public function removePack(int $id, DomainLimit $limit): void
    {
        Gate::authorize('administer');

        DomainPack::query()->whereKey($id)->delete();
        unset($this->packs);

        Flux::toast(text: $limit->allowed() === null
            ? __('Pack removed.')
            : __('Pack removed: this installation now protects up to :allowed domains.', ['allowed' => $limit->allowed()]));
    }
}; ?>

@php
    $limit = app(DomainLimit::class);
    $allowed = $limit->allowed();
    $used = $limit->used();
    $valid = $this->packs->filter->isValid()->count();
@endphp

<div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700" data-test="domain-packs">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="font-medium text-ink dark:text-white">{{ __('Domains') }}</p>
            @if ($allowed === null)
                <p class="mt-1 text-zinc-600 dark:text-zinc-300" data-test="domain-limit">{{ trans_choice(':count domain, no limit.|:count domains, no limit.', $used) }}</p>
            @else
                <p class="mt-1 text-zinc-600 dark:text-zinc-300" data-test="domain-limit">
                    {{ __(':used of :allowed', ['used' => $used, 'allowed' => $allowed]) }}
                    <span class="text-zinc-400">({{ trans_choice(':free free + :count pack|:free free + :count packs', $valid, ['free' => $limit->free()]) }})</span>
                </p>
                @if ($used >= $allowed)
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300" data-test="domain-limit-reached">{{ __('At the limit: new domains need another pack. Hostnames behind an Origins origin don\'t count.') }}</p>
                @else
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Each More domains pack adds :size. Hostnames behind an Origins origin don\'t count.', ['size' => DomainLimit::PACK_SIZE]) }}</p>
                @endif
            @endif
        </div>
        <flux:button size="sm" :href="DomainLimit::buyUrl()" target="_blank" icon:trailing="arrow-top-right-on-square" data-test="buy-domain-pack">{{ __('Buy a pack') }}</flux:button>
    </div>

    @if ($this->packs->isNotEmpty())
        <ul class="mt-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="domain-pack-list">
            @foreach ($this->packs as $pack)
                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="pack-{{ $pack->id }}">
                    <span class="font-mono text-xs">{{ Str::limit($pack->licence_id, 4, '') }}…{{ Str::substr($pack->licence_id, -2) }}</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($pack->isValid())
                            +{{ DomainLimit::PACK_SIZE }}
                        @else
                            <flux:badge size="sm" color="red">{{ __('Refunded') }}</flux:badge>
                        @endif
                        · {{ __('added :date', ['date' => $pack->created_at?->toFormattedDateString()]) }}{{ $pack->licensed_to ? ' · '.$pack->licensed_to : '' }}
                    </span>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="removePack({{ $pack->id }})" wire:confirm="{{ __('Remove this pack? Its :size domains stop counting; the key can be added again.', ['size' => DomainLimit::PACK_SIZE]) }}" data-test="remove-domain-pack" />
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addPack" class="mt-4 flex flex-wrap items-start gap-2">
        <div class="min-w-0 flex-1">
            <flux:input wire:model="packKey" :placeholder="__('Paste a More domains key (ntb1.…)')" class="font-mono text-xs" data-test="domain-pack-key" />
        </div>
        <flux:button type="submit" size="sm" variant="primary" data-test="add-domain-pack">{{ __('Add pack') }}</flux:button>
    </form>
</div>
