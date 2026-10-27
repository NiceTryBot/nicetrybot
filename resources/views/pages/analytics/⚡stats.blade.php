<?php

use App\Actions\Analytics\SummarizeRequestRate;
use App\Concerns\SearchesDomains;
use App\Data\RequestRates;
use App\Enums\StatsRange;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Stats')] class extends Component {
    use SearchesDomains;

    #[Url(except: '')]
    public string $domain = '';

    #[Url(except: '1h')]
    public string $period = '1h';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname']);
    }

    #[Computed]
    public function statsRange(): StatsRange
    {
        return StatsRange::tryFrom($this->period) ?? StatsRange::Hour;
    }

    #[Computed]
    public function rates(): RequestRates
    {
        return app(SummarizeRequestRate::class)->handle(
            $this->team,
            $this->statsRange,
            $this->domains->firstWhere('hostname', $this->domain),
        );
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Who is knocking')"
        :title="__('Analytics')"
        :description="__('Where requests come from and what software sent them: networks, countries, user agents, and TLS fingerprints.')"
    >
        <x-slot:actions>
            <x-ntb.domain-picker wire:model.live="domain" class="w-48" />
        </x-slot:actions>
    </x-ntb.page-heading>

    <x-ntb.analytics-tabs :period="$period" :domain="$domain" />

    @php($range = $this->statsRange)
    @php($rates = $this->rates)
    @php($peakPerSecond = $rates->perSecond->peak())
    @php($peakPerMinute = $rates->perMinute->peak())

    <div class="mb-4 flex flex-wrap items-center gap-3" wire:poll.{{ $range->refreshSeconds() }}s.visible data-test="stats">
        <flux:radio.group wire:model.live="period" variant="segmented" size="sm" :aria-label="__('Time range')">
            @foreach (StatsRange::cases() as $rangeOption)
                <flux:radio :value="$rangeOption->value" :label="$rangeOption->shortLabel()" />
            @endforeach
        </flux:radio.group>

        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="$refresh" :aria-label="__('Refresh')" />

        <span class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Refreshes every :seconds seconds. Times in UTC.', ['seconds' => $range->refreshSeconds()]) }}
        </span>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="period, domain">
        <x-ntb.metric
            :label="__('Requests per second')"
            :value="Number::format($rates->lastMinute / 60, maxPrecision: 2)"
            icon="bolt"
            :hint="__('Average over the last minute')"
        />
        <x-ntb.metric
            :label="__('Requests per minute')"
            :value="Number::format($rates->lastMinute)"
            icon="clock"
            :hint="__('In the last 60 seconds')"
        />
        <x-ntb.metric
            :label="__('Peak per second')"
            :value="Number::format($peakPerSecond['requests'])"
            icon="arrow-trending-up"
            :hint="$peakPerSecond['label'] ? __('Busiest second, around :when', ['when' => $peakPerSecond['label']]) : __('No requests in this range')"
        />
        <x-ntb.metric
            :label="__('Peak per minute')"
            :value="Number::format($peakPerMinute['requests'])"
            icon="chart-bar"
            :hint="$peakPerMinute['label'] ? __('Busiest minute, around :when', ['when' => $peakPerMinute['label']]) : __(':count requests in this range', ['count' => Number::format($rates->requests)])"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-2" wire:loading.class="opacity-60" wire:target="period, domain">
        <x-ntb.panel
            :heading="__('Requests per second')"
            :subheading="__('Average per second, one point every :period', ['period' => $rates->perSecond->periodLabel()])"
            data-test="requests-per-second"
        >
            <x-ntb.rate-chart :series="$rates->perSecond" :unit-label="__('Count/Second')" />
        </x-ntb.panel>

        <x-ntb.panel
            :heading="__('Requests per minute')"
            :subheading="$rates->perMinute->hasPeak()
                ? __('Average per minute, one point every :period', ['period' => $rates->perMinute->periodLabel()])
                : __('Sum per minute')"
            data-test="requests-per-minute"
        >
            <x-ntb.rate-chart :series="$rates->perMinute" :unit-label="__('Count/Minute')" />
        </x-ntb.panel>
    </div>
</div>
