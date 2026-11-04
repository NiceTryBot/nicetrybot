@php
    use NiceTry\TrafficMap\Actions\SummarizeTrafficMap;
    use NiceTry\TrafficMap\Enums\MapDimension;

    /**
     * Rendered inside the Activity component's view slot, so wire: directives here target Activity.
     *
     * @var \App\Models\Team $team
     * @var \App\Enums\TrafficPeriod $period
     * @var int|null $domainId
     * @var array<string, mixed>|null $where
     * @var string $dimension
     * @var string $split
     */
    $selected = MapDimension::tryFrom($dimension) ?? MapDimension::Country;
    $secondary = MapDimension::tryFrom($split ?? '');
    $secondary = $secondary === $selected ? null : $secondary;
    $data = app(SummarizeTrafficMap::class)->handle($team, $selected, $period, $domainId, $where, $secondary);
@endphp

<div class="px-4 py-3" data-test="activity-traffic-map">
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-md border border-line bg-canvas p-0.5 text-xs font-medium dark:border-zinc-700 dark:bg-zinc-900" role="tablist" aria-label="{{ __('Group by') }}">
            @foreach (MapDimension::forTeam() as $option)
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('mapDimension', '{{ $option->value }}')"
                    aria-selected="{{ $selected === $option ? 'true' : 'false' }}"
                    title="{{ $option->description() }}"
                    @class([
                        'rounded px-2.5 py-1 transition',
                        'bg-white text-ink shadow-xs dark:bg-zinc-700 dark:text-white' => $selected === $option,
                        'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => $selected !== $option,
                    ])
                    data-test="map-dim-{{ $option->value }}"
                >{{ $option->label() }}</button>
            @endforeach
        </div>

        <flux:select wire:model.live="mapSplit" size="sm" class="w-40" :aria-label="__('Split by')" data-test="map-split">
            <flux:select.option value="">{{ __('No split') }}</flux:select.option>
            @foreach (MapDimension::forTeam() as $option)
                @continue($option === $selected)
                <flux:select.option :value="$option->value">{{ __('Split by :dim', ['dim' => $option->label()]) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:spacer />
        <flux:button :href="route('traffic-map.index', ['current_team' => $team, 'dimension' => $selected->value, 'split' => $secondary?->value])" wire:navigate size="xs" variant="ghost" icon:trailing="arrow-up-right">{{ __('Open full map') }}</flux:button>
    </div>

    @include('traffic-map::treemap', ['data' => $data, 'height' => '60vh'])
</div>
