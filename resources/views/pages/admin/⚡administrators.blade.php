<?php

use App\Models\Administrator;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Administrators')] class extends Component {
    public string $email = '';

    /**
     * @return Collection<int, Administrator>
     */
    #[Computed]
    public function administrators(): Collection
    {
        return Administrator::query()->with(['user', 'grantedBy'])->oldest('id')->get();
    }

    /**
     * Make a user who has signed up an administrator.
     */
    public function grant(): void
    {
        Gate::authorize('administer');

        $this->validate(['email' => ['required', 'email', 'exists:users,email']], [
            'email.exists' => __('No user has that email address. They need to sign up first.'),
        ]);

        $user = User::query()->where('email', $this->email)->firstOrFail();
        $administrator = Administrator::query()->firstOrCreate(['user_id' => $user->id], ['granted_by_id' => auth()->id()]);

        Flux::toast(variant: 'success', text: $administrator->wasRecentlyCreated
            ? __(':name is now an administrator.', ['name' => $user->name])
            : __(':name was already an administrator.', ['name' => $user->name]));

        $this->reset('email');
        unset($this->administrators);
    }

    /**
     * Stop someone else being an administrator. Nobody removes themselves, so there's always one.
     */
    public function revoke(int $administratorId): void
    {
        Gate::authorize('administer');

        $administrator = Administrator::query()->with('user')->findOrFail($administratorId);
        abort_if($administrator->user_id === auth()->id(), 403, __('You can\'t remove yourself.'));

        $administrator->delete();

        Flux::toast(text: __(':name is no longer an administrator.', ['name' => $administrator->user->name]));
        unset($this->administrators);
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('Administrators')" :subheading="__('Who runs this installation. Administrators manage add-ons and each other, across every team. Running php artisan admin:grant <email> makes one too.')">
        <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="administrators">
            @foreach ($this->administrators as $administrator)
                <div class="flex items-center gap-3 px-4 py-3 text-sm" wire:key="administrator-{{ $administrator->id }}">
                    <flux:avatar size="sm" :initials="$administrator->user->initials()" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-ink dark:text-white">{{ $administrator->user->name }}</p>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $administrator->user->email }} ·
                            {{ $administrator->grantedBy !== null
                                ? __('made one by :name :when', ['name' => $administrator->grantedBy->name, 'when' => $administrator->created_at?->diffForHumans()])
                                : __('made one :when', ['when' => $administrator->created_at?->diffForHumans()]) }}
                        </p>
                    </div>

                    @if ($administrator->user_id === auth()->id())
                        <flux:badge size="sm">{{ __('You') }}</flux:badge>
                    @else
                        <flux:button size="xs" variant="ghost" wire:click="revoke({{ $administrator->id }})" data-test="revoke-administrator">{{ __('Remove') }}</flux:button>
                    @endif
                </div>
            @endforeach
        </div>

        <form wire:submit="grant" class="mt-5 flex items-end gap-2">
            <div class="flex-1">
                <flux:input wire:model="email" type="email" :label="__('Make someone an administrator')" :placeholder="__('Their email address')" />
            </div>
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </form>
    </x-pages::admin.layout>
</section>
