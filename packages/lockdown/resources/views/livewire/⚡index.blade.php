<?php

use App\Models\Domain;
use App\Models\Team;
use App\Support\SharedOrigin;
use App\Support\SharedOrigins;
use App\Support\ViewerIp;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Lockdown\Models\LockdownAllowedIp;

new #[Title('Lockdown')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $show = 'all';

    public string $range = '';

    public string $label = '';

    public string $pageHtml = '';

    public function mount(): void
    {
        Gate::authorize('view', $this->lockdown);

        $this->pageHtml = $this->lockdown->page();
    }

    /**
     * Save the maintenance page the edge serves on the team's locked sites. Saving the stock page (or
     * nothing) keeps following the stock one.
     */
    public function savePage(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->validate([
            'pageHtml' => [
                'nullable',
                'string',
                fn (string $attribute, string $value, Closure $fail) => strlen($value) > Lockdown::MAX_PAGE_BYTES
                    ? $fail(__('Keep the page under :size.', ['size' => Number::fileSize(Lockdown::MAX_PAGE_BYTES)]))
                    : null,
            ],
        ]);

        $html = trim($this->pageHtml) === '' || $this->pageHtml === Lockdown::maintenancePage() ? null : $this->pageHtml;

        $this->lockdown->update(['page_html' => $html]);
        $this->pageHtml = $this->lockdown->page();

        Flux::modal('edit-page')->close();
        Flux::toast(variant: 'success', text: $this->lockdown->isEngaged()
            ? __('Page saved. Locked sites show it within 10 seconds.')
            : __('Page saved.'));
    }

    /**
     * Load the stock page into the editor, to save or keep editing.
     */
    public function useStockPage(): void
    {
        $this->pageHtml = Lockdown::maintenancePage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function lockdown(): Lockdown
    {
        return Lockdown::for($this->team);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('update', $this->lockdown);
    }

    /**
     * Whether shared origins are kept (App\Support\SharedOrigins), so the pool and each site's origin show.
     */
    #[Computed]
    public function hasSharedOrigins(): bool
    {
        return app(SharedOrigins::class)->areKept();
    }

    /**
     * The team's shared origins, each with whether it's in the pool. None while nothing keeps them.
     *
     * @return \Illuminate\Support\Collection<int, array{origin: SharedOrigin, in_pool: bool}>
     */
    #[Computed]
    public function origins(): \Illuminate\Support\Collection
    {
        $origins = app(SharedOrigins::class)->origins($this->team);
        $pooled = $origins->isEmpty() ? collect() : $this->lockdown->pooledOrigins()->pluck('origin_id');

        return $origins->map(fn (SharedOrigin $origin) => ['origin' => $origin, 'in_pool' => $pooled->contains($origin->id)]);
    }

    /**
     * The name of each of the team's shared origins, by id, for the sites' Origin column.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function originNames(): array
    {
        return $this->origins->mapWithKeys(fn (array $entry) => [$entry['origin']->id => $entry['origin']->name])->all();
    }

    #[Computed]
    public function siteCount(): int
    {
        return $this->team->domains()->count();
    }

    #[Computed]
    public function lockedCount(): int
    {
        return $this->lockdown->lockedDomains()->count();
    }

    /**
     * @return LengthAwarePaginator<int, Domain>
     */
    #[Computed]
    public function sites(): LengthAwarePaginator
    {
        return $this->team->domains()
            ->when($this->search !== '', fn ($query) => $query->where('hostname', 'like', '%'.Str::lower(trim($this->search)).'%'))
            ->when($this->show === 'locked', fn ($query) => $this->lockdown->constrainToLocked($query))
            ->when($this->show === 'serving', fn ($query) => $query->whereNot(fn ($query) => $this->lockdown->constrainToLocked($query)))
            ->orderBy('hostname')
            ->paginate(25);
    }

    /**
     * The ids of the sites on this page that the lockdown covers.
     *
     * @return list<int>
     */
    #[Computed]
    public function lockedOnPage(): array
    {
        return $this->lockdown->lockedDomains()->whereKey($this->sites->pluck('id'))->pluck('domains.id')->all();
    }

    /**
     * @return Collection<int, LockdownAllowedIp>
     */
    #[Computed]
    public function allowedIps(): Collection
    {
        return $this->lockdown->allowedIps()->orderBy('range')->get();
    }

    /**
     * When each edge last picked up the lockdowns, and whether that was after the latest change.
     *
     * @return \Illuminate\Support\Collection<string, array{at: \Illuminate\Support\Carbon, current: bool}>
     */
    #[Computed]
    public function edges(): \Illuminate\Support\Collection
    {
        return Lockdown::edgeCheckIns()
            ->filter(fn ($at) => $at->isAfter(now()->subMinutes(5)))
            ->map(fn ($at) => ['at' => $at, 'current' => $at->greaterThanOrEqualTo($this->lockdown->updated_at)]);
    }

    #[Computed]
    public function viewerIp(): ?string
    {
        return ViewerIp::of(request());
    }

    public function engage(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->lockdown->engage();

        Flux::modal('engage-lockdown')->close();
        Flux::toast(variant: 'warning', text: trans_choice('Lockdown engaged. :count site shows the maintenance page within 10 seconds.|Lockdown engaged. :count sites show the maintenance page within 10 seconds.', $this->lockedCount, ['count' => Number::format($this->lockedCount)]));
    }

    public function lift(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->lockdown->lift();

        Flux::modal('lift-lockdown')->close();
        Flux::toast(variant: 'success', text: __('Lockdown lifted. Every site serves normally within 10 seconds.'));
    }

    public function lockEverything(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->lockdown->lockEverything();
        $this->refreshPool();

        Flux::toast(text: trans_choice('Every site is in the lockdown: :count site.|Every site is in the lockdown: :count sites.', $this->lockedCount, ['count' => Number::format($this->lockedCount)]));
    }

    public function toggleOrigin(int $originId): void
    {
        Gate::authorize('update', $this->lockdown);

        abort_unless(isset($this->originNames[$originId]), 404);

        $this->lockdown->pools($originId)
            ? $this->lockdown->removeOrigin($originId)
            : $this->lockdown->addOrigin($originId);

        $this->refreshPool();
    }

    public function lock(int $domainId): void
    {
        Gate::authorize('update', $this->lockdown);

        $domain = $this->team->domains()->findOrFail($domainId);
        $this->lockdown->lock($domain);
        $this->refreshPool();

        Flux::toast(text: __(':host is locked.', ['host' => $domain->hostname]));
    }

    public function release(int $domainId): void
    {
        Gate::authorize('update', $this->lockdown);

        $domain = $this->team->domains()->findOrFail($domainId);
        $this->lockdown->release($domain);
        $this->refreshPool();

        Flux::toast(variant: 'success', text: $this->lockdown->isEngaged()
            ? __(':host is released and serves normally within 10 seconds.', ['host' => $domain->hostname])
            : __(':host is released.', ['host' => $domain->hostname]));
    }

    public function addAllowedIp(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->range = (string) LockdownAllowedIp::normalize($this->range) ?: trim($this->range);

        $this->validate([
            'range' => [
                'required',
                fn (string $attribute, string $value, Closure $fail) => LockdownAllowedIp::normalize($value) === null
                    ? $fail(__('Enter an IP address or a CIDR range, like 203.0.113.4 or 2001:db8::/48.'))
                    : null,
                Rule::unique('lockdown_allowed_ips', 'range')->where('lockdown_id', $this->lockdown->id),
            ],
            'label' => ['nullable', 'string', 'max:100'],
        ], ['range.unique' => __('That address is already on the list.')]);

        $this->lockdown->allowedIps()->create(['range' => $this->range, 'label' => trim($this->label) ?: null]);
        $this->lockdown->touch();

        $this->reset('range', 'label');
        unset($this->allowedIps);
    }

    public function allowMyIp(): void
    {
        $this->range = (string) $this->viewerIp;
        $this->label = Auth::user()->name;

        $this->addAllowedIp();
    }

    public function removeAllowedIp(int $allowedIpId): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->lockdown->allowedIps()->whereKey($allowedIpId)->delete();
        $this->lockdown->touch();

        unset($this->allowedIps);
    }

    public function revokePasses(): void
    {
        Gate::authorize('update', $this->lockdown);

        $this->lockdown->revokePasses();

        Flux::modal('revoke-passes')->close();
        Flux::toast(variant: 'warning', text: __('Every pass is revoked. Browsers that had one see the maintenance page within 10 seconds.'));
    }

    private function refreshPool(): void
    {
        unset($this->origins, $this->originNames, $this->lockedCount, $this->sites, $this->lockedOnPage, $this->edges);
    }
}; ?>

<div @if ($this->lockdown->isEngaged()) wire:poll.10s @endif>
    <x-ntb.page-heading
        :overline="__('Emergency')"
        :title="__('Lockdown')"
        :description="__('Put sites behind a plain Scheduled Maintenance page at the edge. Every request to a locked site, GET or POST, gets the page and nothing reaches your origins. Release sites one at a time when they\'re ready.')"
    >
        <x-slot:actions>
            <flux:button size="sm" icon="eye" :href="route('lockdown.preview')" target="_blank" data-test="preview-maintenance-page">{{ __('Preview the page') }}</flux:button>
            @if ($this->canManage)
                <flux:modal.trigger name="edit-page">
                    <flux:button size="sm" icon="code-bracket" data-test="open-edit-page">{{ $this->lockdown->page_html === null ? __('Edit the page') : __('Edit your page') }}</flux:button>
                </flux:modal.trigger>
            @endif
            @if ($this->canManage)
                @if ($this->lockdown->isEngaged())
                    <flux:modal.trigger name="lift-lockdown">
                        <flux:button size="sm" variant="primary" icon="lock-open" data-test="open-lift-lockdown">{{ __('Lift lockdown') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:modal.trigger name="engage-lockdown">
                        <flux:button size="sm" variant="danger" icon="lock-closed" data-test="open-engage-lockdown">{{ __('Engage lockdown') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            @endif
        </x-slot:actions>
    </x-ntb.page-heading>

    <section @class([
        'mb-8 rounded-xl border p-5',
        'border-rose-300 bg-rose-50 dark:border-rose-500/40 dark:bg-rose-500/10' => $this->lockdown->isEngaged(),
        'border-line bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $this->lockdown->isEngaged(),
    ]) data-test="lockdown-status">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                @if ($this->lockdown->isEngaged())
                    <flux:icon.lock-closed class="mt-0.5 size-6 text-rose-600 dark:text-rose-400" />
                    <div>
                        <p class="text-base font-semibold">{{ __('Engaged :when', ['when' => $this->lockdown->engaged_at->diffForHumans()]) }}</p>
                        <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">{{ trans_choice(':locked of :total site shows the maintenance page.|:locked of :total sites show the maintenance page.', $this->siteCount, ['locked' => Number::format($this->lockedCount), 'total' => Number::format($this->siteCount)]) }}</p>
                    </div>
                @else
                    <flux:icon.lock-open class="mt-0.5 size-6 text-zinc-400" />
                    <div>
                        <p class="text-base font-semibold">{{ __('Off') }}</p>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice('Every site serves normally. :locked of :total site would be locked.|Every site serves normally. :locked of :total sites would be locked.', $this->siteCount, ['locked' => Number::format($this->lockedCount), 'total' => Number::format($this->siteCount)]) }}</p>
                    </div>
                @endif
            </div>

            <div class="text-end text-xs text-zinc-500 dark:text-zinc-400" data-test="lockdown-edges">
                @forelse ($this->edges as $edge => $checkIn)
                    <p wire:key="edge-{{ $edge }}" class="flex items-center justify-end gap-1.5">
                        <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $checkIn['current'], 'bg-amber-500' => ! $checkIn['current']])></span>
                        <span class="font-mono">{{ $edge }}</span>
                        {{ $checkIn['current'] ? __('has the latest, :when', ['when' => $checkIn['at']->diffForHumans(short: true)]) : __('picking it up…') }}
                    </p>
                @empty
                    <p>{{ __('No edge has checked in for 5 minutes.') }}</p>
                @endforelse
            </div>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-6">
            <x-ntb.panel :heading="__('Sites')" :subheading="__('A site of an origin in the pool is locked unless you release it. A site you lock or release here keeps that choice.')">
                @if ($this->canManage)
                    <x-slot:actions>
                        <flux:button size="sm" icon="lock-closed" wire:click="lockEverything" data-test="lock-everything">{{ __('Lock everything') }}</flux:button>
                    </x-slot:actions>
                @endif

                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Find a site')" icon="magnifying-glass" size="sm" class="max-w-xs" spellcheck="false" data-test="lockdown-site-search" />
                    <flux:radio.group wire:model.live="show" variant="segmented" size="sm">
                        <flux:radio value="all" :label="__('All')" />
                        <flux:radio value="locked" :label="__('Locked')" />
                        <flux:radio value="serving" :label="__('Serving')" />
                    </flux:radio.group>
                </div>

                @if ($this->sites->isEmpty())
                    <div class="py-6 text-center">
                        <flux:icon.globe-alt class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-3 text-sm font-medium">{{ $search === '' && $show === 'all' ? __('No sites yet') : __('No site matches that') }}</p>
                    </div>
                @else
                    <flux:table :paginate="$this->sites">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Site') }}</flux:table.column>
                            @if ($this->hasSharedOrigins)
                                <flux:table.column class="max-sm:hidden">{{ __('Origin') }}</flux:table.column>
                            @endif
                            <flux:table.column>{{ __('State') }}</flux:table.column>
                            <flux:table.column align="end"></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->sites as $site)
                                @php($isLocked = in_array($site->id, $this->lockedOnPage, true))
                                <flux:table.row :key="$site->id" data-test="lockdown-site">
                                    <flux:table.cell class="max-w-80">
                                        <flux:link :href="route('domains.show', $site)" wire:navigate class="block truncate font-mono text-xs">{{ $site->hostname }}</flux:link>
                                    </flux:table.cell>
                                    @if ($this->hasSharedOrigins)
                                        <flux:table.cell class="max-w-48 truncate text-xs text-zinc-500 max-sm:hidden dark:text-zinc-400">{{ $this->originNames[$site->origin_id] ?? __('Its own') }}</flux:table.cell>
                                    @endif
                                    <flux:table.cell>
                                        @if ($isLocked)
                                            <flux:badge size="sm" :color="$this->lockdown->isEngaged() ? 'red' : 'zinc'" icon="lock-closed">{{ __('Locked') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="emerald" icon="check">{{ __('Serving') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        @if ($this->canManage)
                                            <div class="flex items-center justify-end gap-1">
                                                @if ($isLocked && $this->lockdown->isEngaged())
                                                    <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('lockdown.pass', $site)" target="_blank" :tooltip="__('Open the real site in a new tab. Your browser gets a pass for 12 hours.')" data-test="open-past-lockdown" />
                                                @endif
                                                @if ($isLocked)
                                                    <flux:button size="xs" icon="lock-open" wire:click="release({{ $site->id }})" data-test="release-site">{{ __('Release') }}</flux:button>
                                                @else
                                                    <flux:button size="xs" variant="ghost" icon="lock-closed" wire:click="lock({{ $site->id }})" data-test="lock-site">{{ __('Lock') }}</flux:button>
                                                @endif
                                            </div>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </x-ntb.panel>
        </div>

        <div class="space-y-6">
            @if ($this->origins->isNotEmpty())
                <x-ntb.panel :heading="__('Origins in the pool')" :subheading="__('Locks every site of the origin, including sites that join it later.')">
                    <div class="space-y-3">
                        @foreach ($this->origins as ['origin' => $origin, 'in_pool' => $inPool])
                            <div class="flex items-center justify-between gap-3" wire:key="pool-origin-{{ $origin->id }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $origin->name }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count site|:count sites', $origin->siteCount, ['count' => Number::format($origin->siteCount)]) }}</p>
                                </div>
                                <flux:switch :checked="$inPool" wire:click="toggleOrigin({{ $origin->id }})" :disabled="! $this->canManage" data-test="pool-origin" />
                            </div>
                        @endforeach
                    </div>
                </x-ntb.panel>
            @endif

            <x-ntb.panel :heading="__('Allowed addresses')" :subheading="__('Requests from these IPs reach the real sites while the lockdown is engaged.')">
                @if ($this->allowedIps->isNotEmpty())
                    <ul class="mb-4 divide-y divide-line dark:divide-zinc-800">
                        @foreach ($this->allowedIps as $allowedIp)
                            <li class="flex items-center justify-between gap-3 py-2" wire:key="allowed-ip-{{ $allowedIp->id }}" data-test="allowed-ip">
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-xs">{{ $allowedIp->range }}</p>
                                    @if ($allowedIp->label)
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $allowedIp->label }}</p>
                                    @endif
                                </div>
                                @if ($this->canManage)
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeAllowedIp({{ $allowedIp->id }})" :aria-label="__('Remove')" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->canManage)
                    <form wire:submit="addAllowedIp" class="space-y-3">
                        <flux:input wire:model="range" :placeholder="__('203.0.113.4 or 198.51.100.0/24')" size="sm" spellcheck="false" data-test="allowed-ip-range" />
                        <flux:input wire:model="label" :placeholder="__('Label (optional)')" size="sm" />
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($this->viewerIp && ! $this->allowedIps->contains('range', $this->viewerIp))
                                <flux:button size="sm" variant="ghost" wire:click="allowMyIp" data-test="allow-my-ip">{{ __('Add my IP (:ip)', ['ip' => $this->viewerIp]) }}</flux:button>
                            @endif
                            <flux:button size="sm" type="submit" data-test="add-allowed-ip">{{ __('Add') }}</flux:button>
                        </div>
                    </form>
                @endif
            </x-ntb.panel>

            <x-ntb.panel :heading="__('Passes')" :subheading="__('The open button next to a locked site gives your browser a 12-hour pass to the real site. Nothing on the maintenance page leads here.')">
                @if ($this->canManage)
                    <flux:modal.trigger name="revoke-passes">
                        <flux:button size="sm" variant="filled" icon="key">{{ __('Revoke every pass') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </x-ntb.panel>
        </div>
    </div>

    @if ($this->canManage)
    <flux:modal name="engage-lockdown" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Engage the lockdown?') }}</flux:heading>
                <flux:subheading>
                    @if ($this->lockedCount === 0)
                        {{ __('Nothing is locked yet. Lock everything or add origins first, or engage now and lock sites as you go.') }}
                    @else
                        {{ trans_choice('Within 10 seconds :count site shows the maintenance page for every request. Nothing reaches its origin until you release it or lift the lockdown.|Within 10 seconds :count sites show the maintenance page for every request. Nothing reaches their origins until you release them or lift the lockdown.', $this->lockedCount, ['count' => Number::format($this->lockedCount)]) }}
                    @endif
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="engage" data-test="confirm-engage-lockdown">{{ __('Engage lockdown') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="lift-lockdown" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Lift the lockdown?') }}</flux:heading>
                <flux:subheading>{{ __('Every site serves normally again within 10 seconds. What\'s locked stays set for next time.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="lift" data-test="confirm-lift-lockdown">{{ __('Lift lockdown') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="edit-page" class="w-full md:max-w-6xl">
        <form wire:submit="savePage" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Maintenance page') }}</flux:heading>
                <flux:subheading>{{ __('The HTML every visitor to a locked site gets. Keep styles and images inline (data: URLs): the site behind it is locked, so its own files won\'t load.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <flux:textarea wire:model="pageHtml" rows="22" resize="none" class="font-mono text-xs" spellcheck="false" :aria-label="__('Page HTML')" data-test="page-html" />
                <iframe
                    x-data
                    x-bind:srcdoc="$wire.pageHtml"
                    sandbox
                    title="{{ __('Preview') }}"
                    class="h-[30rem] w-full rounded-lg border border-line bg-white dark:border-zinc-800"
                ></iframe>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:button variant="ghost" size="sm" icon="arrow-uturn-left" wire:click="useStockPage" data-test="use-stock-page">{{ __('Start from the stock page') }}</flux:button>
                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-page">{{ __('Save page') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="revoke-passes" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Revoke every pass?') }}</flux:heading>
                <flux:subheading>{{ __('Every browser holding a pass, yours included, sees the maintenance page again. Allowed addresses still get through.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="revokePasses">{{ __('Revoke passes') }}</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</div>
