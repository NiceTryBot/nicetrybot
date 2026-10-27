@php
    /** @var \App\Gym\GymStep|null $step */
    $obstacleDefinition = $step?->obstacle ?? $demoObstacle;
    $banner = match (true) {
        $isDemo => __('Live preview of :obstacle. Nothing is submitted.', ['obstacle' => $obstacleDefinition->name()]),
        (bool) $session?->is_preview => __('Preview. This walk through the gym doesn’t count as a visit.'),
        default => null,
    };
    $runtime = [
        'attemptUrl' => $attemptUrl,
        'step' => $step?->key,
        'demo' => $isDemo,
    ];
    // An obstacle that is its own page is drawn bare: no step bar, no card around it.
    $isBare = (bool) ($obstacle['bare'] ?? false);
@endphp

<x-layouts::gym :title="__('One moment…')" :banner="$banner" :help="$session?->visitorHelp()">
    @include('gym.partials.runtime')

    @if ($isBare)
        <div class="flex w-full flex-col items-center" data-test="gym-visit" data-obstacle="{{ $obstacleDefinition->key() }}">
            @if ($notice)
                <p class="mb-5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200" role="status" data-test="gym-notice">{{ $notice }}</p>
            @endif

            @include($obstacleDefinition->view(), ['obstacle' => $obstacle, 'runtime' => $runtime])
        </div>
    @else
        <div class="w-full max-w-md" data-test="gym-visit" data-obstacle="{{ $obstacleDefinition->key() }}">
            @if (count($steps) > 1)
                <div class="mb-4 px-1" aria-label="{{ __('Step :current of :total', ['current' => $current + 1, 'total' => count($steps)]) }}">
                    <div class="flex gap-1.5">
                        @foreach ($steps as $index => $listedStep)
                            <span @class([
                                'h-1 flex-1 rounded-full',
                                'bg-coral-500' => $index < $current,
                                'bg-coral-500/60 gym-breathe' => $index === $current,
                                'bg-zinc-200 dark:bg-zinc-800' => $index > $current,
                            ])></span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[0.7rem] text-zinc-500 dark:text-zinc-400">{{ __('Step :current of :total', ['current' => $current + 1, 'total' => count($steps)]) }}</p>
                </div>
            @endif

            <div class="rounded-2xl border border-line bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                @if ($notice)
                    <p class="mb-5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200" role="status" data-test="gym-notice">{{ $notice }}</p>
                @endif

                @include($obstacleDefinition->view(), ['obstacle' => $obstacle, 'runtime' => $runtime])
            </div>
        </div>
    @endif

    {{-- What packages add to the page (App\Gym\GymAddons), e.g. a fingerprinting script. --}}
    {{ $addons ?? '' }}
</x-layouts::gym>
