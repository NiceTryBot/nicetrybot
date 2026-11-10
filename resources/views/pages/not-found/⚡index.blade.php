<?php

use App\Actions\Analytics\SummarizeNotFound;
use App\Concerns\SearchesDomains;
use App\Data\NotFoundSummary;
use App\Data\RequestQuery;
use App\Enums\NotFoundCategory;
use App\Enums\NotFoundGrouping;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('404s')] class extends Component {
    use SearchesDomains;

    #[Url(except: '')]
    public string $domain = '';

    #[Url(except: '7d')]
    public string $period = '7d';

    #[Url(as: 'group', except: '')]
    public string $groupBy = '';

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
        return $this->team->domains()->orderBy('hostname')->get(['id', 'team_id', 'hostname']);
    }

    #[Computed]
    public function trafficPeriod(): TrafficPeriod
    {
        return TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Week;
    }

    #[Computed]
    public function grouping(): ?NotFoundGrouping
    {
        return NotFoundGrouping::tryFrom($this->groupBy);
    }

    #[Computed]
    public function summary(): NotFoundSummary
    {
        $domain = $this->domains->firstWhere('hostname', $this->domain);

        // Kept a minute or more (and served stale while it's counted again), as plain arrays: the
        // cache unserializes no classes.
        $summary = Cache::flexible(
            "not-found:summary:{$this->team->id}:{$this->trafficPeriod->value}:".($domain?->id ?? 'all'),
            $this->trafficPeriod->cacheSeconds(),
            fn (): array => $this->toCacheable(app(SummarizeNotFound::class)->handle($this->team, $this->trafficPeriod, $domain)),
        );

        return new NotFoundSummary(...[
            ...$summary,
            'paths' => $this->pathsFromCache($summary['paths']),
        ]);
    }

    /**
     * The missing paths in their groups, kept the same way as the summary.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function pathGroups(): array
    {
        if ($this->grouping === null) {
            return [];
        }

        $domain = $this->domains->firstWhere('hostname', $this->domain);

        $groups = Cache::flexible(
            "not-found:groups:{$this->team->id}:{$this->trafficPeriod->value}:{$this->grouping->value}:".($domain?->id ?? 'all'),
            $this->trafficPeriod->cacheSeconds(),
            fn (): array => array_map(
                fn (array $group): array => [...$group, 'paths' => $this->pathsToCache($group['paths'])],
                app(SummarizeNotFound::class)->groupedPaths($this->team, $this->trafficPeriod, $this->grouping, $domain),
            ),
        );

        return array_map(fn (array $group): array => [...$group, 'paths' => $this->pathsFromCache($group['paths'])], $groups);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toCacheable(NotFoundSummary $summary): array
    {
        return [
            ...get_object_vars($summary),
            'paths' => $this->pathsToCache($summary->paths),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $paths
     * @return array<int, array<string, mixed>>
     */
    protected function pathsToCache(array $paths): array
    {
        return array_map(fn (array $path): array => [...$path, 'category' => $path['category']->value], $paths);
    }

    /**
     * @param  array<int, array<string, mixed>>  $paths
     * @return array<int, array<string, mixed>>
     */
    protected function pathsFromCache(array $paths): array
    {
        return array_map(fn (array $path): array => [...$path, 'category' => NotFoundCategory::from($path['category'])], $paths);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function categoryColors(): array
    {
        return [
            NotFoundCategory::Probe->value => 'bg-(--chart-suspicious)',
            NotFoundCategory::BrowserAsset->value => 'bg-(--chart-human)',
            NotFoundCategory::MissingPage->value => 'bg-zinc-400 dark:bg-zinc-500',
        ];
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Your 404s have a story to tell')"
        :title="__('404s')"
        :description="__('Which domains get the most 404s, what was asked for, and whether it was a person or a probe.')"
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

    @php($summary = $this->summary)

    @if ($summary->total === 0)
        <x-ntb.panel>
            <x-ntb.empty-state :heading="__('Not a single 404.')" image="images/firefly.png">
                {{ $this->domains->isEmpty()
                    ? __('Add a domain and its 404s will show up here, sorted into probes, browser assets, and broken links.')
                    : __('Nothing went missing in this period. Try a longer period, or enjoy it.') }}
            </x-ntb.empty-state>
        </x-ntb.panel>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60">
            <x-ntb.metric :label="__('404s logged')" :value="Number::format($summary->total)" icon="document-magnifying-glass" :hint="$this->trafficPeriod->label()" />
            <x-ntb.metric :label="__('Distinct paths')" :value="Number::format($summary->uniquePaths)" icon="squares-2x2" :hint="__('requested at least once')" />
            <x-ntb.metric :label="__('From scanner probes')" :value="$summary->shareFor(NotFoundCategory::Probe).'%'" icon="bug-ant" :hint="__(':count requests', ['count' => Number::format($summary->totalFor(NotFoundCategory::Probe))])" />
            <x-ntb.metric :label="__('Unique IPs')" :value="Number::format($summary->uniqueIps)" icon="globe-americas" :hint="__('asked for something that isn\'t there')" />
        </div>

        <x-ntb.panel class="mb-6" :heading="__('Person or probe?')" :subheading="__('Every 404 path, sorted by what it most likely is.')">
            <div class="flex h-3 gap-[2px] overflow-hidden rounded-full" role="img" aria-label="{{ __('404s by category') }}">
                @foreach (NotFoundCategory::cases() as $category)
                    @if ($summary->totalFor($category) > 0)
                        <div class="{{ $this->categoryColors[$category->value] }}" style="width: {{ $summary->shareFor($category) }}%" wire:key="share-{{ $category->value }}"></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                @foreach (NotFoundCategory::cases() as $category)
                    <div class="flex gap-3" wire:key="legend-{{ $category->value }}">
                        <span class="mt-1 size-2.5 shrink-0 rounded-sm {{ $this->categoryColors[$category->value] }}"></span>
                        <div>
                            <p class="text-sm font-semibold">
                                {{ $category->label() }}
                                <span class="ms-1 font-mono text-xs font-normal text-zinc-500 tabular-nums dark:text-zinc-400">{{ Number::format($summary->totalFor($category)) }} · {{ $summary->shareFor($category) }}%</span>
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $category->description() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ntb.panel>

        <div class="grid gap-6 xl:grid-cols-3">
            <x-ntb.panel :heading="__('Which domains get the most 404s?')" :subheading="__('A question you couldn\'t answer before the edge logged real hostnames.')">
                @php($mostHits = max(1, collect($summary->domains)->max('hits')))

                <ul class="space-y-4">
                    @foreach ($summary->domains as $domainRow)
                        <li wire:key="nf-domain-row-{{ md5($domainRow['hostname']) }}">
                            <div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                                <a href="{{ route('not-found.index', ['domain' => $domainRow['hostname'], 'period' => $period, 'group' => $groupBy ?: null]) }}" class="truncate font-medium hover:underline" wire:navigate>{{ $domainRow['hostname'] }}</a>
                                <span class="font-mono text-xs tabular-nums">{{ Number::format($domainRow['hits']) }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-2 rounded-full bg-ink dark:bg-zinc-300" style="width: {{ $domainRow['hits'] / $mostHits * 100 }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ntb.panel>

            <x-ntb.panel class="xl:col-span-2" :heading="__('Most requested missing paths')" :subheading="__('Probes are worth enforcing on. Browser assets and broken links are worth fixing.')" wire:loading.class="opacity-60">
                <x-slot:actions>
                    <flux:select wire:model.live="groupBy" size="sm" class="w-48" :aria-label="__('Group by')">
                        <flux:select.option value="">{{ __('Group by: nothing') }}</flux:select.option>
                        @foreach (NotFoundGrouping::cases() as $groupingOption)
                            <flux:select.option :value="$groupingOption->value">{{ __('Group by: :grouping', ['grouping' => $groupingOption->label()]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </x-slot:actions>

                @php($grouping = $this->grouping)
                @php($showsLooksLike = $grouping !== NotFoundGrouping::LooksLike)

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Path') }}</flux:table.column>
                        @if ($showsLooksLike)
                            <flux:table.column>{{ __('Looks like') }}</flux:table.column>
                        @endif
                        <flux:table.column align="end">{{ __('Hits') }}</flux:table.column>
                        <flux:table.column align="end" class="max-md:hidden">{{ __('IPs') }}</flux:table.column>
                        <flux:table.column align="end" class="max-md:hidden">{{ __('Last seen') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($grouping ? $this->pathGroups : [['key' => null, 'paths' => $summary->paths]] as $pathGroup)
                            @if ($grouping)
                                <flux:table.row :key="'group-'.md5((string) $pathGroup['key'])">
                                    <flux:table.cell :colspan="$showsLooksLike ? 2 : 1" class="pt-6">
                                        <a href="{{ route('activity.index', ['q' => $grouping->activityClause($pathGroup['key']), 'status' => '404', 'period' => $period, 'domain' => $domain]) }}" class="font-semibold text-ink hover:underline dark:text-white" wire:navigate>{{ $grouping->groupLabel($pathGroup['key']) }}</a>
                                        <span class="ms-1 font-mono text-xs tabular-nums">{{ round($pathGroup['hits'] / $summary->total * 100, 1) }}% · {{ trans_choice(':count path|:count paths', $pathGroup['distinctPaths'], ['count' => Number::format($pathGroup['distinctPaths'])]) }}</span>
                                        @if ($description = $grouping->groupDescription($pathGroup['key']))
                                            <p class="mt-0.5 text-xs whitespace-normal">{{ $description }}</p>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="pt-6 font-mono text-xs tabular-nums">{{ Number::format($pathGroup['hits']) }}</flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="pt-6 font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($pathGroup['uniqueIps']) }}</flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="pt-6 text-xs whitespace-nowrap max-md:hidden">{{ CarbonImmutable::parse($pathGroup['lastSeenAt'])->diffForHumans(short: true) }}</flux:table.cell>
                                </flux:table.row>
                            @endif

                            @foreach ($pathGroup['paths'] as $path)
                                <flux:table.row :key="md5($pathGroup['key'].'|'.$path['path'])">
                                    <flux:table.cell class="max-w-72">
                                        <a href="{{ route('activity.index', ['q' => 'path = '.RequestQuery::quote($path['path']), 'status' => '404', 'period' => $period, 'domain' => $domain]) }}" class="block truncate font-mono text-xs text-ink hover:underline dark:text-zinc-100" wire:navigate>{{ $path['path'] }}</a>
                                        @if ($path['domains'] > 1)
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('on :count domains', ['count' => $path['domains']]) }}</span>
                                        @endif
                                    </flux:table.cell>
                                    @if ($showsLooksLike)
                                        <flux:table.cell><flux:badge size="sm" :color="$path['category']->color()">{{ $path['category']->label() }}</flux:badge></flux:table.cell>
                                    @endif
                                    <flux:table.cell align="end" variant="strong" class="font-mono text-xs tabular-nums">{{ Number::format($path['hits']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($path['uniqueIps']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="text-xs whitespace-nowrap max-md:hidden">{{ CarbonImmutable::parse($path['lastSeenAt'])->diffForHumans(short: true) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ntb.panel>
        </div>

        <div class="mt-4 flex gap-3 rounded-xl border border-dashed border-line px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
            <p>{{ __('A 404 alone doesn\'t establish abuse. Repetition, suspicious paths, and the same visitor across many domains add context. Categories are a best guess from the path.') }}</p>
        </div>
    @endif
</div>
