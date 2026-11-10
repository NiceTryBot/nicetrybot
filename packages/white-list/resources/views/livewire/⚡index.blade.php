<?php

use App\Enums\ViewSlot;
use App\Support\ViewerIp;
use App\Support\ViewSlots;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use NiceTry\WhiteList\Models\WhitelistEntry;

new #[Title('White List')] class extends Component {
    use WithPagination;

    /**
     * Categories offered as quick picks when there are none yet to borrow from.
     */
    public const array SUGGESTED = ['bot/crawler', 'bot/ai', 'bot/seo', 'office/vpn', 'partner/scanner'];

    /**
     * The chosen tab: the hand-kept list, or the good bots an add-on fills in (Nice Bots).
     */
    #[Url(except: 'entries')]
    public string $tab = 'entries';

    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $category = '';

    public string $rangesText = '';

    public string $reason = '';

    public bool $enabled = true;

    #[Locked]
    public ?string $viewerIp = null;

    public function mount(): void
    {
        $this->viewerIp = ViewerIp::of(request());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function entryCount(): int
    {
        return WhitelistEntry::query()->count();
    }

    #[Computed]
    public function enabledCount(): int
    {
        return WhitelistEntry::query()->enabled()->count();
    }

    /**
     * Whether a "Good bots" tab should show: an add-on (Nice Bots) has filled the slot and is on.
     */
    #[Computed]
    public function hasGoodBots(): bool
    {
        return app(ViewSlots::class)->views(ViewSlot::WhiteListGoodBots) !== [];
    }

    /**
     * The tab to show: the chosen one, unless Good bots was chosen and nothing fills it.
     */
    #[Computed]
    public function selectedTab(): string
    {
        return $this->tab === 'good-bots' && $this->hasGoodBots ? 'good-bots' : 'entries';
    }

    /**
     * The entries, newest first, or those matching a search by name, category or range.
     *
     * @return LengthAwarePaginator<int, WhitelistEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $like = '%'.addcslashes($search, '%_\\').'%';

        return WhitelistEntry::query()
            ->with('addedBy:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhere('ranges', 'like', $like)))
            ->latest('id')
            ->paginate(25);
    }

    /**
     * The categories already in use, so they can be reused rather than retyped.
     *
     * @return list<string>
     */
    #[Computed]
    public function categories(): array
    {
        $used = WhitelistEntry::query()->distinct()->orderBy('category')->pluck('category')->all();

        return array_values(array_unique([...$used, ...self::SUGGESTED]));
    }

    /**
     * Open the modal to add a new entry.
     */
    public function startAdd(): void
    {
        Gate::authorize('administer');

        $this->reset('editingId', 'name', 'category', 'rangesText', 'reason');
        $this->enabled = true;
        $this->resetValidation();

        Flux::modal('white-list-entry')->show();
    }

    /**
     * Open the modal to edit an entry.
     */
    public function edit(int $id): void
    {
        Gate::authorize('administer');

        $entry = WhitelistEntry::query()->findOrFail($id);

        $this->editingId = $entry->id;
        $this->name = $entry->name;
        $this->category = $entry->category;
        $this->rangesText = implode("\n", $entry->ranges);
        $this->reason = (string) $entry->reason;
        $this->enabled = $entry->enabled;
        $this->resetValidation();

        Flux::modal('white-list-entry')->show();
    }

    /**
     * Create or update an entry from the form.
     */
    public function save(): void
    {
        Gate::authorize('administer');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/'],
            'rangesText' => ['required', 'string', 'max:20000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'category.regex' => __('Write the category as two words with a slash, like bot/crawler.'),
        ]);

        $ranges = WhitelistEntry::parseRanges($this->rangesText);

        if ($ranges === []) {
            $this->addError('rangesText', __('None of those are IP addresses or CIDR ranges.'));

            return;
        }

        $values = [
            'name' => trim($this->name),
            'category' => $this->category,
            'ranges' => $ranges,
            'reason' => trim($this->reason) ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->editingId !== null) {
            WhitelistEntry::query()->findOrFail($this->editingId)->update($values);
            $text = __('Saved.');
        } else {
            WhitelistEntry::query()->create([...$values, 'added_by_id' => auth()->id()]);
            $text = __(':name is on the list.', ['name' => $values['name']]);
        }

        Flux::modal('white-list-entry')->close();
        Flux::toast(variant: 'success', text: $text);

        $this->reset('editingId', 'name', 'category', 'rangesText', 'reason');
        $this->enabled = true;
        unset($this->entries, $this->entryCount, $this->enabledCount, $this->categories);
    }

    /**
     * Turn an entry on or off. An off entry keeps its addresses but stops allowing them.
     */
    public function toggle(int $id): void
    {
        Gate::authorize('administer');

        $entry = WhitelistEntry::query()->findOrFail($id);
        $entry->update(['enabled' => ! $entry->enabled]);

        Flux::toast(text: $entry->enabled
            ? __(':name is allowing again.', ['name' => $entry->name])
            : __(':name is off. Its addresses meet enforcements again.', ['name' => $entry->name]));

        unset($this->entries, $this->enabledCount);
    }

    public function delete(int $id): void
    {
        Gate::authorize('administer');

        $entry = WhitelistEntry::query()->findOrFail($id);
        $entry->delete();

        Flux::toast(text: __(':name is off the list.', ['name' => $entry->name]));

        unset($this->entries, $this->entryCount, $this->enabledCount, $this->categories);
    }

    /**
     * A badge colour for a category, from its first word.
     */
    public function categoryColor(string $category): string
    {
        return match (strtok($category, '/')) {
            'bot' => 'sky',
            'office' => 'emerald',
            'partner' => 'violet',
            default => 'zinc',
        };
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('White List')" :subheading="__('Addresses that never meet an enforcement — never sent to the gym, held in a tarpit, blocked, or dropped in the blackhole — on any site, while still showing up in Activity and reports')">
        @if ($this->hasGoodBots)
            <div class="mb-5 flex gap-1 border-b border-line text-sm dark:border-zinc-800" role="tablist" aria-label="{{ __('White List') }}" data-test="tabs">
                @foreach (['entries' => __('The list'), 'good-bots' => __('Good bots')] as $tabOption => $tabLabel)
                    <button
                        type="button"
                        role="tab"
                        wire:key="tab-{{ $tabOption }}"
                        wire:click="$set('tab', '{{ $tabOption }}')"
                        aria-selected="{{ $this->selectedTab === $tabOption ? 'true' : 'false' }}"
                        @class([
                            '-mb-px border-b-2 px-3 py-2 font-medium transition',
                            'border-coral-500 text-ink dark:text-white' => $this->selectedTab === $tabOption,
                            'border-transparent text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => $this->selectedTab !== $tabOption,
                        ])
                        data-test="tab-{{ $tabOption }}"
                    >{{ $tabLabel }}</button>
                @endforeach
            </div>
        @endif

        @if ($this->selectedTab === 'good-bots')
            <div data-test="good-bots">{{ app(ViewSlots::class)->render(ViewSlot::WhiteListGoodBots) }}</div>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <flux:input wire:model.live.debounce.300ms="search" size="sm" class="max-w-xs" icon="magnifying-glass" :placeholder="__('Search name, category or range')" />
                <flux:spacer />
                <p class="text-xs text-zinc-500 dark:text-zinc-400" data-test="counts">{{ trans_choice(':count entry|:count entries', $this->entryCount, ['count' => number_format($this->entryCount)]) }} · {{ __(':count allowing', ['count' => number_format($this->enabledCount)]) }}</p>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startAdd" data-test="add-entry">{{ __('Add an entry') }}</flux:button>
            </div>

            @if ($this->entries->isEmpty())
                <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400" data-test="empty">{{ trim($search) === '' ? __('Nothing is allowed yet.') : __('Nothing matches that.') }}</p>
            @else
                <div class="mt-4 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="entries">
                    @foreach ($this->entries as $entry)
                        <div class="flex items-start gap-3 px-4 py-3 text-sm" wire:key="entry-{{ $entry->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-ink dark:text-white">{{ $entry->name }}</span>
                                    <flux:badge size="sm" :color="$this->categoryColor($entry->category)">{{ $entry->category }}</flux:badge>
                                    @unless ($entry->enabled)
                                        <flux:badge size="sm" color="zinc" variant="outline" data-test="off-badge">{{ __('Off') }}</flux:badge>
                                    @endunless
                                </div>
                                <p class="mt-1 truncate font-mono text-xs text-zinc-600 dark:text-zinc-300" data-test="ranges">{{ \Illuminate\Support\Str::limit(implode(', ', $entry->ranges), 160) }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ trans_choice(':count range|:count ranges', count($entry->ranges), ['count' => number_format(count($entry->ranges))]) }}
                                    @if ($entry->reason) · {{ $entry->reason }}@endif
                                    @if ($entry->addedBy) · {{ __('added by :name', ['name' => $entry->addedBy->name]) }}@endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="toggle({{ $entry->id }})" data-test="toggle">{{ $entry->enabled ? __('Turn off') : __('Turn on') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $entry->id }})" data-test="edit">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Remove this entry? Its addresses meet enforcements again.') }}" data-test="delete">{{ __('Remove') }}</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3"><flux:pagination :paginator="$this->entries" /></div>
            @endif
        @endif

        <flux:modal name="white-list-entry" class="md:w-lg">
            <form wire:submit="save" class="space-y-4" data-test="entry-form">
                <flux:heading size="lg">{{ $editingId === null ? __('Add an entry') : __('Edit entry') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Googlebot')" />
                <flux:input wire:model="category" :label="__('Category')" placeholder="bot/crawler" :description="__('Two words with a slash: a kind, then a sort.')" />
                @if ($this->categories !== [])
                    <div class="-mt-2 flex flex-wrap gap-1" data-test="category-picks">
                        @foreach ($this->categories as $pick)
                            <flux:badge as="button" type="button" size="sm" :color="$this->categoryColor($pick)" wire:click="$set('category', '{{ $pick }}')" wire:key="pick-{{ $pick }}">{{ $pick }}</flux:badge>
                        @endforeach
                    </div>
                @endif
                <flux:textarea wire:model="rangesText" :label="__('Addresses')" rows="6" placeholder="203.0.113.9&#10;66.249.64.0/27&#10;2001:4860:4801:10::/64" :description="__('One IP address or CIDR range per line (commas are fine too). A visitor on any of them is never enforced.')" />
                <flux:switch wire:model="enabled" :label="__('Allowing')" :description="__('Off keeps the addresses but stops allowing them.')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save">{{ $editingId === null ? __('Add to the list') : __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::admin.layout>
</section>
