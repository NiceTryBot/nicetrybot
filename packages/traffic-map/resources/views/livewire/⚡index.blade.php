<?php

use App\Concerns\SearchesDomains;
use App\Enums\TrafficPeriod;
use App\Enums\ViewSlot;
use App\Models\LiveTail;
use App\Models\Team;
use App\Support\ViewSlots;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use NiceTry\TrafficMap\Actions\SummarizeTrafficMap;
use NiceTry\TrafficMap\Data\TreemapData;
use NiceTry\TrafficMap\Enums\MapDimension;

new #[Title('Traffic Map')] class extends Component {
    use SearchesDomains;

    /** The periods offered on the map. */
    private const array PERIODS = ['1h', '6h', '24h', '7d'];

    #[Url(except: '')]
    public string $domain = '';

    #[Url(except: '24h')]
    public string $period = '24h';

    #[Url(except: 'country')]
    public string $dimension = 'country';

    #[Url(except: '')]
    public string $split = '';

    public bool $live = false;

    public function mount(): void
    {
        $this->live = $this->liveTail?->isRunning() ?? false;
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, \App\Models\Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname']);
    }

    #[Computed]
    public function liveTail(): ?LiveTail
    {
        return $this->team->liveTails()->firstWhere('user_id', Auth::id());
    }

    #[Computed]
    public function map(): TreemapData
    {
        $primary = MapDimension::tryFrom($this->dimension) ?? MapDimension::Country;
        $secondary = MapDimension::tryFrom($this->split);

        return app(SummarizeTrafficMap::class)->handle(
            $this->team,
            $primary,
            TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day,
            $this->filteredDomainId(),
            secondary: $secondary === $primary ? null : $secondary,
        );
    }

    public function updatedDimension(): void
    {
        if ($this->split === $this->dimension) {
            $this->split = '';
        }
    }

    /**
     * @return list<TrafficPeriod>
     */
    public function periods(): array
    {
        return array_map(fn (string $value): TrafficPeriod => TrafficPeriod::from($value), self::PERIODS);
    }

    protected function filteredDomainId(): ?int
    {
        return $this->domain === '' ? null : $this->domains->firstWhere('hostname', $this->domain)?->id;
    }

    /**
     * Start a ten-minute live tail (the same one Activity uses) of the filtered domain, or stop it.
     */
    public function toggleLive(): void
    {
        if ($this->live) {
            $this->liveTail?->delete();
            $this->live = false;
            unset($this->liveTail);

            return;
        }

        $this->team->liveTails()->updateOrCreate(['user_id' => Auth::id()], [
            'domain_id' => $this->filteredDomainId(),
            'expires_at' => now()->addMinutes(LiveTail::LIFETIME_MINUTES),
            'last_seen_at' => now(),
        ]);

        $this->live = true;
        unset($this->liveTail);
    }

    /**
     * Polled while live: keep the tail going and redraw the map, or stop once it has ended.
     */
    public function heartbeat(): void
    {
        $tail = $this->liveTail;

        if ($tail === null || ! $tail->isRunning()) {
            $tail?->delete();
            $this->live = false;
            unset($this->liveTail);

            return;
        }

        $tail->update(['last_seen_at' => now(), 'domain_id' => $this->filteredDomainId()]);
        unset($this->map);
    }
}; ?>

<div @if ($live) wire:poll.5s="heartbeat" @endif>
    <x-ntb.page-heading
        :overline="__('Where it all lands')"
        :title="__('Traffic Map')"
        :description="__('A live treemap of your edge traffic: each tile is a group of requests, sized by volume and coloured by the share the edge did not pass to origin.')"
    >
        <x-slot:actions>
            <x-ntb.domain-picker wire:model.live="domain" class="w-48" />
        </x-slot:actions>
    </x-ntb.page-heading>

    <div class="mb-4 flex flex-wrap items-center gap-3" data-test="traffic-map-controls">
        <flux:radio.group wire:model.live="dimension" variant="segmented" size="sm" :aria-label="__('Group by')">
            @foreach (MapDimension::forTeam() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:radio.group wire:model.live="period" variant="segmented" size="sm" :aria-label="__('Time range')">
            @foreach ($this->periods() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:select wire:model.live="split" size="sm" class="w-40" :aria-label="__('Split by')" data-test="traffic-map-split">
            <flux:select.option value="">{{ __('No split') }}</flux:select.option>
            @foreach (MapDimension::forTeam() as $option)
                @continue($option->value === $this->dimension)
                <flux:select.option :value="$option->value">{{ __('Split by :dim', ['dim' => $option->label()]) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:spacer />

        @if ($live)
            <flux:button wire:click="toggleLive" size="sm" variant="primary" icon="signal" data-test="live-stop">{{ __('Live') }}</flux:button>
        @else
            <flux:button wire:click="toggleLive" size="sm" variant="ghost" icon="signal" data-test="live-start">{{ __('Go live') }}</flux:button>
            <flux:button wire:click="$refresh" size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Refresh')" />
        @endif
    </div>

    {{-- Right-click an IP for what add-ons offer on a client. With nothing to offer, the browser's own menu stays. --}}
    @php($clientMenu = trim((string) app(ViewSlots::class)->render(ViewSlot::ActivityClientMenu, ['team' => $this->team])))
    <div
        wire:loading.class="opacity-60"
        wire:target="dimension, period, domain"
        @if ($clientMenu !== '')
            x-data="{
                open: false, x: 0, y: 0, kind: '', value: '', asn: '',
                show(event) {
                    const client = event.target.closest('[data-client-kind]');
                    if (! client) return;
                    event.preventDefault();
                    Object.assign(this, { kind: client.dataset.clientKind, value: client.dataset.clientValue, asn: client.dataset.clientAsn || '', x: event.clientX, y: event.clientY, open: true });
                    this.$nextTick(() => {
                        const box = this.$refs.menu.getBoundingClientRect();
                        this.x = Math.max(8, Math.min(this.x, window.innerWidth - box.width - 8));
                        this.y = Math.max(8, Math.min(this.y, window.innerHeight - box.height - 8));
                    });
                },
            }"
            x-on:contextmenu="show($event)"
            x-on:keydown.escape.window="open = false"
            x-on:scroll.window="open = false"
        @endif
    >
        @include('traffic-map::treemap', ['data' => $this->map, 'height' => '62vh'])

        @if ($clientMenu !== '')
            <div
                x-show="open"
                style="display: none"
                x-ref="menu"
                x-on:click.outside="open = false"
                x-bind:style="{ left: x + 'px', top: y + 'px' }"
                class="fixed z-50 w-60 rounded-lg border border-line bg-white p-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                role="menu"
                data-test="traffic-map-client-menu"
            >
                <p class="truncate px-2 pt-1.5 pb-1 font-mono text-xs text-zinc-500 dark:text-zinc-400" x-text="value"></p>
                {!! $clientMenu !!}
            </div>
        @endif
    </div>

    {{ app(ViewSlots::class)->render(ViewSlot::ActivityModals, ['team' => $this->team]) }}
</div>
