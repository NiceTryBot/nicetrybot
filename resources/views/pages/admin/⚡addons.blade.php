<?php

use App\Addons\Addon;
use App\Addons\Addons;
use App\Addons\AddonSwitchboard;
use App\Addons\InvalidLicenceKey;
use App\Addons\Licences;
use App\Addons\LicenceState;
use App\Models\AddonLicence;
use App\Models\AddonSwitch;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add-ons')] class extends Component {
    /**
     * The add-on the confirmation is about, and what it confirms: "off" or "remove".
     */
    #[Locked]
    public ?string $confirming = null;

    #[Locked]
    public string $confirmingAction = '';

    /**
     * The add-on a licence key is being entered for, and the key.
     */
    #[Locked]
    public ?string $licensing = null;

    public string $licenceKey = '';

    /**
     * The add-ons this deploy shipped.
     *
     * @return list<Addon>
     */
    #[Computed]
    public function addons(): array
    {
        return app(Addons::class)->all();
    }

    /**
     * What administrators changed, by add-on slug.
     *
     * @return Collection<string, AddonSwitch>
     */
    #[Computed]
    public function switches(): Collection
    {
        return AddonSwitch::query()->with(['turnedOffBy', 'removalRequestedBy'])->orderBy('slug')->get()->keyBy('slug');
    }

    /**
     * The installed add-ons waiting for a deploy to remove them.
     *
     * @return list<Addon>
     */
    #[Computed]
    public function awaitingRemoval(): array
    {
        return array_values(array_filter($this->addons, fn (Addon $addon): bool => (bool) $this->switches->get($addon->slug)?->isAwaitingRemoval()));
    }

    /**
     * Switches whose add-on the deploy no longer has: removed as asked, or by hand.
     *
     * @return Collection<string, AddonSwitch>
     */
    #[Computed]
    public function departed(): Collection
    {
        return $this->switches->reject(fn (AddonSwitch $switch): bool => app(Addons::class)->has($switch->slug));
    }

    /**
     * Ask before turning an add-on off or removing it.
     */
    public function confirm(string $action, string $slug): void
    {
        Gate::authorize('administer');
        abort_unless(in_array($action, ['off', 'remove'], true), 404);
        $this->installed($slug);

        $this->confirming = $slug;
        $this->confirmingAction = $action;

        Flux::modal('addon-confirm')->show();
    }

    /**
     * Do what the confirmation asked.
     */
    public function apply(): void
    {
        Gate::authorize('administer');
        $addon = $this->installed((string) $this->confirming);

        $switch = AddonSwitch::query()->firstOrNew(['slug' => $addon->slug]);

        if (! $switch->isOff()) {
            $switch->fill(['turned_off_at' => now(), 'turned_off_by_id' => auth()->id()]);
        }

        if ($this->confirmingAction === 'remove') {
            $switch->fill(['removal_requested_at' => now(), 'removal_requested_by_id' => auth()->id()]);
        }

        $switch->save();

        Flux::modal('addon-confirm')->close();
        Flux::toast(variant: 'success', text: $this->confirmingAction === 'remove'
            ? __(':name is off, and leaves with the next deploy.', ['name' => $addon->name])
            : __(':name is off, on every server.', ['name' => $addon->name]));

        $this->reset('confirming', 'confirmingAction');
        unset($this->switches);
    }

    /**
     * Turn an add-on back on, on every server, and drop any removal asked for.
     */
    public function turnOn(string $slug): void
    {
        Gate::authorize('administer');
        $addon = $this->installed($slug);

        AddonSwitch::query()->where('slug', $addon->slug)->first()?->delete();

        Flux::toast(variant: 'success', text: __(':name is on, on every server.', ['name' => $addon->name]));
        unset($this->switches);
    }

    /**
     * Keep an add-on that was going to be removed. It stays off until turned on.
     */
    public function cancelRemoval(string $slug): void
    {
        Gate::authorize('administer');
        $addon = $this->installed($slug);

        AddonSwitch::query()->where('slug', $addon->slug)->first()?->update(['removal_requested_at' => null, 'removal_requested_by_id' => null]);

        Flux::toast(text: __(':name stays installed, and off.', ['name' => $addon->name]));
        unset($this->switches);
    }

    /**
     * Forget the switch of an add-on the deploy no longer has, so it starts on if it comes back.
     */
    public function dismiss(string $slug): void
    {
        Gate::authorize('administer');
        abort_if(app(Addons::class)->has($slug), 404);

        AddonSwitch::query()->where('slug', $slug)->delete();
        unset($this->switches);
    }

    /**
     * Open the form for a paid add-on's licence key.
     */
    public function enterLicence(string $slug): void
    {
        Gate::authorize('administer');
        abort_unless($this->installed($slug)->paid, 404);

        $this->licensing = $slug;
        $this->licenceKey = '';
        $this->resetValidation();

        Flux::modal('addon-licence')->show();
    }

    /**
     * Keep the key entered, once it's proven and the marketplace has been asked about it.
     */
    public function saveLicence(): void
    {
        Gate::authorize('administer');
        $addon = $this->installed((string) $this->licensing);
        $this->validate(['licenceKey' => ['required', 'string', 'max:4000']]);

        try {
            $licence = app(Licences::class)->enter($addon, $this->licenceKey, auth()->user());
        } catch (InvalidLicenceKey $e) {
            throw ValidationException::withMessages(['licenceKey' => $e->getMessage()]);
        }

        Flux::modal('addon-licence')->close();

        if ($licence->isRevoked()) {
            Flux::toast(variant: 'danger', text: __('The marketplace says that licence was revoked. :name stays off until another key is entered.', ['name' => $addon->name]));
        } elseif ($licence->checked_at === null) {
            Flux::toast(variant: 'warning', text: __(':name is licensed to :to. The marketplace couldn’t be reached to confirm it; it’s asked again daily.', ['name' => $addon->name, 'to' => $licence->licensed_to ?: __('you')]));
        } else {
            Flux::toast(variant: 'success', text: __(':name is licensed to :to, on every server.', ['name' => $addon->name, 'to' => $licence->licensed_to ?: __('you')]));
        }

        $this->reset('licensing', 'licenceKey');
    }

    /**
     * Ask the marketplace about an add-on's licence now, rather than waiting for the daily check.
     */
    public function checkLicence(string $slug): void
    {
        Gate::authorize('administer');
        $addon = $this->installed($slug);
        $licence = AddonLicence::query()->where('slug', $addon->slug)->whereNotNull('licence_id')->first();
        abort_if($licence === null, 404);

        if (! app(Licences::class)->check($licence)) {
            Flux::toast(variant: 'warning', text: __('The marketplace couldn’t be reached. The last answer stands.'));

            return;
        }

        Flux::toast(variant: $licence->isValid() ? 'success' : 'danger', text: $licence->isValid()
            ? __('The marketplace confirms :name’s licence.', ['name' => $addon->name])
            : __('The marketplace says :name’s licence was revoked.', ['name' => $addon->name]));
    }

    /**
     * Forget an add-on's licence key. Its trial doesn't start over.
     */
    public function removeLicence(string $slug): void
    {
        Gate::authorize('administer');
        $addon = $this->installed($slug);

        app(Licences::class)->remove($addon);

        Flux::toast(text: __(':name’s licence key was removed.', ['name' => $addon->name]));
    }

    private function installed(string $slug): Addon
    {
        $addon = app(Addons::class)->find($slug);
        abort_if($addon === null, 404);

        return $addon;
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('Add-ons')" :subheading="__('What this deploy installed. Turning one off reaches every server on its next request, no deploy needed. Installing or removing one changes the code, so that waits for a deploy.')">
        @if ($this->awaitingRemoval !== [])
            <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10" data-test="addons-awaiting-deploy">
                <p class="font-medium text-ink dark:text-white">{{ trans_choice(':count add-on is waiting for a deploy to remove it.|:count add-ons are waiting for a deploy to remove them.', count($this->awaitingRemoval)) }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ __('They\'re off already. Run this where the code lives, commit, and deploy. Their tables and data stay until you drop them.') }}</p>
                <x-ntb.copy-field class="mt-3" :label="__('Command')" :value="'composer remove '.collect($this->awaitingRemoval)->pluck('package')->implode(' ')" />
            </div>
        @endif

        <livewire:domain-packs />

        <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="addons">
            @forelse ($this->addons as $addon)
                @php($switch = $this->switches->get($addon->slug))
                @php($state = app(Licences::class)->state($addon))
                @php($licence = $addon->paid ? app(Licences::class)->find($addon->slug) : null)
                @php($trialEndsAt = $addon->paid ? app(Licences::class)->trialEndsAt($addon) : null)

                <div class="px-4 py-3 text-sm" wire:key="addon-{{ $addon->slug }}" data-test="addon-{{ $addon->slug }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-ink dark:text-white">{{ $addon->name }}</span>
                        @if ($addon->version !== null)
                            <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $addon->version }}</span>
                        @endif
                        <flux:badge size="sm" :color="$addon->paid ? 'amber' : 'zinc'">{{ $addon->paid ? __('Paid') : __('Free') }}</flux:badge>
                        @if ($addon->paid)
                            <flux:badge size="sm" :color="$state->color()" data-test="addon-licence">
                                @if ($state === LicenceState::Trial && $trialEndsAt !== null)
                                    {{ trans_choice('Trial: :count day left|Trial: :count days left', max(0, (int) ceil(now()->diffInDays($trialEndsAt, false))), ['count' => max(0, (int) ceil(now()->diffInDays($trialEndsAt, false)))]) }}
                                @else
                                    {{ $state->label() }}
                                @endif
                            </flux:badge>
                        @endif
                        <flux:spacer />

                        @if ($switch?->isAwaitingRemoval())
                            <flux:badge size="sm" color="amber" data-test="addon-status">{{ __('Removing at next deploy') }}</flux:badge>
                        @elseif ($switch?->isOff())
                            <flux:badge size="sm" color="zinc" data-test="addon-status">{{ __('Off') }}</flux:badge>
                        @elseif (! app(AddonSwitchboard::class)->isOn($addon->slug))
                            <flux:badge size="sm" color="zinc" data-test="addon-status">{{ __('Off: no licence') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="emerald" data-test="addon-status">{{ __('On') }}</flux:badge>
                        @endif
                    </div>

                    <p class="mt-0.5 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $addon->package }}</p>
                    @if ($addon->description !== '')
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $addon->description }}</p>
                    @endif

                    @if ($addon->paid)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-test="addon-licence-note">
                            @if ($state === LicenceState::Licensed)
                                {{ __('Licensed to :to.', ['to' => $licence?->licensed_to ?: __('you')]) }}
                                @if ($licence?->updates_until !== null)
                                    {{ $licence->getsUpdates() ? __('Updates until :date.', ['date' => $licence->updates_until->toFormattedDateString()]) : __('Updates ended :date; the key still works.', ['date' => $licence->updates_until->toFormattedDateString()]) }}
                                @endif
                                {{ $licence?->checked_at !== null ? __('Confirmed by the marketplace :when.', ['when' => $licence->checked_at->diffForHumans()]) : __('Not yet confirmed by the marketplace.') }}
                            @elseif ($state === LicenceState::Revoked)
                                {{ __('The marketplace revoked this licence :when. It\'s off until another key is entered.', ['when' => $licence?->checked_at?->diffForHumans() ?? '']) }}
                            @elseif ($state === LicenceState::TrialEnded)
                                {{ __('No licence key. Its trial ended :when, so it\'s off until a key is entered.', ['when' => $trialEndsAt?->diffForHumans() ?? '']) }}
                            @elseif ($trialEndsAt !== null)
                                {{ __('No licence key. It runs until :date, then goes off until a key is entered.', ['date' => $trialEndsAt->toFormattedDateString()]) }}
                            @else
                                {{ __('No licence key. Keys aren\'t enforced in the :env environment.', ['env' => app()->environment()]) }}
                            @endif
                            <a href="{{ config('addons.marketplace.url') }}/marketplace/{{ $addon->slug }}" class="underline decoration-zinc-400 underline-offset-2 hover:text-ink dark:hover:text-white" target="_blank" rel="noopener">{{ __('Buy a key') }}</a>
                        </p>
                    @endif

                    @if ($switch?->isOff())
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($switch->isAwaitingRemoval())
                                {{ __('Removal asked for by :name :when.', ['name' => $switch->removalRequestedBy?->name ?? __('someone'), 'when' => $switch->removal_requested_at?->diffForHumans()]) }}
                            @else
                                {{ __('Turned off by :name :when.', ['name' => $switch->turnedOffBy?->name ?? __('someone'), 'when' => $switch->turned_off_at?->diffForHumans()]) }}
                            @endif
                        </p>
                    @endif

                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($switch?->isOff())
                            <flux:button size="xs" variant="primary" wire:click="turnOn('{{ $addon->slug }}')" data-test="addon-turn-on">{{ __('Turn on') }}</flux:button>
                        @else
                            <flux:button size="xs" wire:click="confirm('off', '{{ $addon->slug }}')" data-test="addon-turn-off">{{ __('Turn off') }}</flux:button>
                        @endif

                        @if ($addon->paid)
                            <flux:button size="xs" :variant="$state->allowsRunning() && $state !== LicenceState::Trial ? 'ghost' : 'primary'" wire:click="enterLicence('{{ $addon->slug }}')" data-test="addon-enter-licence">
                                {{ $licence?->hasKey() ? __('Replace key…') : __('Enter licence key…') }}
                            </flux:button>
                            @if ($licence?->licence_id !== null)
                                <flux:button size="xs" variant="ghost" wire:click="checkLicence('{{ $addon->slug }}')" data-test="addon-check-licence">{{ __('Check now') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="removeLicence('{{ $addon->slug }}')" wire:confirm="{{ __('Remove the key? The add-on\'s trial doesn\'t start over.') }}" data-test="addon-remove-licence">{{ __('Remove key') }}</flux:button>
                            @endif
                        @endif

                        @if ($switch?->isAwaitingRemoval())
                            <flux:button size="xs" variant="ghost" wire:click="cancelRemoval('{{ $addon->slug }}')" data-test="addon-cancel-removal">{{ __('Keep it installed') }}</flux:button>
                        @else
                            <flux:button size="xs" variant="ghost" wire:click="confirm('remove', '{{ $addon->slug }}')" data-test="addon-remove">{{ __('Remove…') }}</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No add-ons installed. NiceTryBot runs on its own; add-ons bring more.') }}
                </div>
            @endforelse
        </div>

        @if ($this->departed->isNotEmpty())
            <div class="mt-5 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="addons-departed">
                @foreach ($this->departed as $switch)
                    <div class="flex items-center gap-2 px-4 py-2.5 text-sm" wire:key="departed-{{ $switch->slug }}">
                        <span class="font-mono text-xs text-ink dark:text-white">{{ $switch->slug }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $switch->isAwaitingRemoval() ? __('removed by a deploy, as asked') : __('no longer installed') }}</span>
                        <flux:spacer />
                        <flux:button size="xs" variant="ghost" wire:click="dismiss('{{ $switch->slug }}')">{{ __('Dismiss') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-5 rounded-xl border border-zinc-200 p-4 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            <p class="font-medium text-ink dark:text-white">{{ __('Installing an add-on') }}</p>
            <p class="mt-1">{{ __('Add-ons are Composer packages. Require one where the code lives, commit, and deploy: it\'s on once the deploy lands, and its migrations run with it.') }}</p>
            <x-ntb.copy-field class="mt-3" :label="__('Command')" value="composer require vendor/package" />
        </div>

        <div class="mt-5 rounded-xl border border-zinc-200 p-4 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300" data-test="addons-licensing">
            <p class="font-medium text-ink dark:text-white">{{ __('Licence keys') }}</p>
            <p class="mt-1">{{ __('A paid add-on needs a licence key from the marketplace: one for this installation, every team included, good for ever, with a year of updates. The key is checked here, then with the marketplace once a day. Without one a paid add-on runs for :days days from when this installation first saw it, then goes off until a key is entered.', ['days' => config('addons.licensing.trial_days')]) }}</p>
            <p class="mt-1">{{ __('From the command line:') }}</p>
            <x-ntb.copy-field class="mt-2" :label="__('Command')" value="php artisan addons:licence captchas ntb1…" />
        </div>

        <flux:modal name="addon-confirm" class="max-w-lg">
            @if ($confirming !== null && ($confirmingAddon = app(\App\Addons\Addons::class)->find($confirming)) !== null)
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">
                            {{ $confirmingAction === 'remove' ? __('Remove :name?', ['name' => $confirmingAddon->name]) : __('Turn :name off?', ['name' => $confirmingAddon->name]) }}
                        </flux:heading>
                        <flux:text class="mt-2">{{ __('It goes off for every team, on every server, from the next request: its pages, links, gym obstacles and what it sends the edge stop. Its data stays, and is still pruned and erased on schedule.') }}</flux:text>
                        @if ($confirmingAction === 'remove')
                            <flux:text class="mt-2">{{ __('Its code leaves with the next deploy, once :package is removed with Composer. Until then you can keep it.', ['package' => $confirmingAddon->package]) }}</flux:text>
                        @endif
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="apply" data-test="addon-confirm">
                            {{ $confirmingAction === 'remove' ? __('Turn off and remove') : __('Turn off') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>

        <flux:modal name="addon-licence" class="max-w-lg">
            @if ($licensing !== null && ($licensingAddon = app(\App\Addons\Addons::class)->find($licensing)) !== null)
                <form wire:submit="saveLicence" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Licence key for :name', ['name' => $licensingAddon->name]) }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Paste the key from your account on the marketplace. It starts with ntb1. and is proven here, then confirmed with the marketplace.') }}</flux:text>
                    </div>

                    <flux:textarea wire:model="licenceKey" rows="4" :label="__('Licence key')" placeholder="ntb1.…" class="font-mono text-xs" data-test="addon-licence-key" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="addon-licence-save">{{ __('Save key') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>
    </x-pages::admin.layout>
</section>
