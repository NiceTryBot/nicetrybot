<?php

use App\Actions\Analytics\SummarizeBreakdown;
use App\Concerns\SearchesDomains;
use App\Data\BreakdownSummary;
use App\Enums\TrafficBreakdown;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Analytics')] class extends Component {
    use SearchesDomains;

    #[Url(as: 'by', except: 'networks')]
    public string $breakdown = 'networks';

    #[Url(except: '')]
    public string $domain = '';

    #[Url(except: '7d')]
    public string $period = '7d';

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
    public function trafficBreakdown(): TrafficBreakdown
    {
        return TrafficBreakdown::tryFrom($this->breakdown) ?? TrafficBreakdown::Networks;
    }

    #[Computed]
    public function trafficPeriod(): TrafficPeriod
    {
        return TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Week;
    }

    #[Computed]
    public function summary(): BreakdownSummary
    {
        return app(SummarizeBreakdown::class)->handle(
            $this->team,
            $this->trafficBreakdown,
            $this->trafficPeriod,
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

            <flux:select wire:model.live="period" size="sm" class="w-40" :aria-label="__('Time period')">
                @foreach (TrafficPeriod::cases() as $periodOption)
                    <flux:select.option :value="$periodOption->value">{{ $periodOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-ntb.page-heading>

    <x-ntb.analytics-tabs :period="$period" :domain="$domain" />

    <div class="mb-4 overflow-x-auto">
        <flux:radio.group wire:model.live="breakdown" variant="segmented" size="sm" :aria-label="__('Group requests by')">
            @foreach (TrafficBreakdown::cases() as $breakdownOption)
                <flux:radio :value="$breakdownOption->value" :label="$breakdownOption->label()" />
            @endforeach
        </flux:radio.group>
    </div>

    @php($summary = $this->summary)
    @php($breakdown = $this->trafficBreakdown)

    <x-ntb.panel :heading="$breakdown->label()" :subheading="$breakdown->description()" :padded="false">
        @if ($summary->isEmpty())
            <x-ntb.empty-state :heading="__('No requests in this period.')">
                {{ $this->domains->isEmpty()
                    ? __('Add a domain and point it at the edge to see who is visiting.')
                    : __('Try a longer period or another domain.') }}
            </x-ntb.empty-state>
        @else
            <div class="px-4 pb-2" wire:loading.class="opacity-60">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ $breakdown->label() }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Suspicious') }}</flux:table.column>
                        <flux:table.column align="end" class="max-md:hidden">{{ __('Unique IPs') }}</flux:table.column>
                        <flux:table.column align="end" class="max-md:hidden">{{ $breakdown->relatedLabel() }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($summary->rows as $row)
                            <flux:table.row :key="$breakdown->value.'-'.($row->key ?? 'none')" data-test="breakdown-row">
                                <flux:table.cell class="max-w-[28rem] whitespace-normal">
                                    <div class="flex items-center gap-2">
                                        @if ($breakdown === TrafficBreakdown::Countries)
                                            <x-ntb.country :code="$row->key" :show-code="false" />
                                        @endif
                                        @if ($filter = $breakdown->activityFilter($row))
                                            <a href="{{ route('activity.index', [...$filter, 'period' => $period, 'domain' => $domain]) }}" wire:navigate @class([
                                                'truncate hover:underline',
                                                'font-mono text-xs' => in_array($breakdown, [TrafficBreakdown::UserAgents, TrafficBreakdown::Fingerprints], true),
                                                'font-medium' => ! in_array($breakdown, [TrafficBreakdown::UserAgents, TrafficBreakdown::Fingerprints], true),
                                            ]) title="{{ $row->label }}">{{ $row->label }}</a>
                                        @else
                                            <span class="truncate text-zinc-500 dark:text-zinc-400">{{ $row->label }}</span>
                                        @endif
                                        @if ($row->isHosting)
                                            <flux:badge size="sm" color="amber">{{ __('Hosting') }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($row->detail)
                                        <span @class(['block text-[0.65rem] text-zinc-500 dark:text-zinc-400', 'truncate font-mono' => $breakdown !== TrafficBreakdown::Categories])>{{ $row->detail }}</span>
                                    @endif
                                    @if ($row->rule)
                                        <details class="mt-1 text-[0.65rem]" data-test="category-rule">
                                            <summary class="cursor-pointer text-zinc-500 select-none hover:text-ink dark:text-zinc-400 dark:hover:text-white">{{ __('Patterns & rule') }}</summary>
                                            <p class="mt-1.5 font-mono break-words whitespace-normal text-zinc-600 dark:text-zinc-300">{{ implode('  ', $row->patterns) }}</p>
                                            <p class="mt-2 text-zinc-500 dark:text-zinc-400">{{ __('As an edge rule (Caddy matcher):') }}</p>
                                            <code class="mt-1 block max-h-32 overflow-y-auto rounded bg-zinc-100 px-2 py-1.5 font-mono break-all whitespace-normal text-zinc-700 select-all dark:bg-zinc-800 dark:text-zinc-200">{{ $row->rule }}</code>
                                        </details>
                                    @endif
                                    <div class="mt-1.5 h-1 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div class="h-1 rounded-full bg-ink dark:bg-zinc-300" style="width: {{ max(1, $row->shareOf($summary->total)) }}%"></div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end" variant="strong" class="font-mono text-xs tabular-nums">
                                    {{ Number::format($row->requests) }}
                                    <span class="block font-normal text-zinc-400">{{ $row->shareOf($summary->total) }}%</span>
                                </flux:table.cell>
                                <flux:table.cell align="end" @class(['font-mono text-xs tabular-nums', 'text-coral-700 dark:text-coral-300' => $row->suspicious > 0])>{{ Number::format($row->suspicious) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($row->uniqueIps) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($row->related) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </x-ntb.panel>

    <div class="mt-4 flex gap-3 rounded-xl border border-dashed border-line px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
        <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
        <p>
            {{ __('Network and location are looked up at the edge from the client IP. A location is where an IP is registered or routed, not necessarily where a person is.') }}
            <a href="https://db-ip.com" target="_blank" rel="noopener" class="underline">{{ __('IP geolocation by DB-IP') }}</a>
            ({{ __('CC BY 4.0') }}).
        </p>
    </div>
</div>
