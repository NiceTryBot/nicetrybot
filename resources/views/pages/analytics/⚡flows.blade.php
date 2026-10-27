<?php

use App\Actions\Analytics\BuildTrafficFlow;
use App\Concerns\SearchesDomains;
use App\Data\SankeyDiagram;
use App\Enums\TrafficFlow;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Flows')] class extends Component {
    use SearchesDomains;

    #[Url(as: 'report', except: 'identity')]
    public string $flow = 'identity';

    #[Url(except: '')]
    public string $domain = '';

    #[Url(except: '7d')]
    public string $period = '7d';

    #[Url(as: 'paths', except: 'grouped')]
    public string $pathGrouping = 'grouped';

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
    public function trafficFlow(): TrafficFlow
    {
        return TrafficFlow::tryFrom($this->flow) ?? TrafficFlow::Identity;
    }

    #[Computed]
    public function diagram(): SankeyDiagram
    {
        return app(BuildTrafficFlow::class)->handle(
            $this->team,
            $this->trafficFlow,
            TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Week,
            $this->domains->firstWhere('hostname', $this->domain),
            groupPaths: $this->pathGrouping !== 'exact',
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

            <flux:select wire:model.live="period" size="sm" class="w-40" :aria-label="__('Time period')">
                @foreach (TrafficPeriod::cases() as $periodOption)
                    <flux:select.option :value="$periodOption->value">{{ $periodOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-ntb.page-heading>

    <x-ntb.analytics-tabs :period="$period" :domain="$domain" />

    <div class="mb-4 overflow-x-auto">
        <flux:radio.group wire:model.live="flow" variant="segmented" size="sm" :aria-label="__('Flow report')">
            @foreach (TrafficFlow::cases() as $flowOption)
                <flux:radio :value="$flowOption->value" :label="$flowOption->shortLabel()" />
            @endforeach
        </flux:radio.group>
    </div>

    @php($diagram = $this->diagram)
    @php($flowReport = $this->trafficFlow)

    @if ($flowReport->hasPathStage())
        <div class="mb-4 flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="font-medium">{{ __('Paths') }}</span>
            <flux:radio.group wire:model.live="pathGrouping" variant="segmented" size="sm" :aria-label="__('How to show paths')">
                <flux:radio value="grouped" :label="__('By category')" />
                <flux:radio value="exact" :label="__('Exact')" />
            </flux:radio.group>
            <span>
                {{ $pathGrouping === 'exact'
                    ? __('Every path on its own.')
                    : __('Paths matching a known pattern are grouped, e.g. every .env file is probe/creds. Hover a category to see its paths.') }}
                <a href="{{ route('analytics.index', ['by' => 'categories', 'period' => $period, 'domain' => $domain]) }}" wire:navigate class="underline">{{ __('See the patterns') }}</a>
            </span>
        </div>
    @endif

    <x-ntb.panel :heading="$flowReport->label()" :subheading="$flowReport->description()">
        @if ($diagram->isEmpty())
            <x-ntb.empty-state :heading="__('No traffic to trace in this period.')">
                {{ __('Try a longer period or another domain.') }}
            </x-ntb.empty-state>
        @else
            <x-ntb.sankey :diagram="$diagram" :unit="$flowReport->unit()" :period="$period" :domain="$domain" wire:loading.class="opacity-60" />
        @endif
    </x-ntb.panel>

    <div class="mt-4 flex gap-3 rounded-xl border border-dashed border-line px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
        <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
        <p>
            {{ __('Each stage shows its 8 busiest values; the rest are folded into Other. Network and location come from the client IP.') }}
            <a href="https://db-ip.com" target="_blank" rel="noopener" class="underline">{{ __('IP geolocation by DB-IP') }}</a>
            ({{ __('CC BY 4.0') }}).
        </p>
    </div>
</div>
