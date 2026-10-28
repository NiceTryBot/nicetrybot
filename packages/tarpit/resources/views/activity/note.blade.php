@use('NiceTry\Tarpit\Enums\TarpitOutcome')
<span class="mt-1 flex items-center gap-1 text-[0.65rem] whitespace-nowrap text-zinc-500 dark:text-zinc-400" data-test="tarpit-outcome">
    <span @class([
        'size-1.5 shrink-0 rounded-full',
        'bg-zinc-400' => $hold->outcome === TarpitOutcome::Free,
        'bg-violet-500' => $hold->outcome === TarpitOutcome::Held,
        'bg-amber-500' => $hold->outcome === TarpitOutcome::Full,
        'bg-rose-500' => $hold->outcome === TarpitOutcome::Left,
    ])></span>
    @switch($hold->outcome)
        @case(TarpitOutcome::Free)
            {{ __('Free try') }}
            @break
        @case(TarpitOutcome::Held)
            {{ __('Held :time', ['time' => \Illuminate\Support\Number::format($hold->held_ms / 1000, maxPrecision: 1).' s']) }} · {{ __('try :n', ['n' => $hold->try]) }}
            @break
        @case(TarpitOutcome::Full)
            {{ __('Full') }} · {{ __('try :n', ['n' => $hold->try]) }}
            @break
        @case(TarpitOutcome::Left)
            {{ __('Gave up after :time', ['time' => \Illuminate\Support\Number::format($hold->held_ms / 1000, maxPrecision: 1).' s']) }}
            @break
    @endswitch
</span>
