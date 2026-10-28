@use('NiceTry\Tarpit\Enums\TarpitOutcome')
@php($seconds = \Illuminate\Support\Number::format($hold->held_ms / 1000, maxPrecision: 1).' s')
<p class="flex items-start gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-900 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200" data-test="tarpit-details">
    <flux:icon.clock variant="micro" class="mt-0.5 size-3.5 shrink-0" />
    <span>
        {{ __('In the tarpit :name because :why.', ['name' => $hold->tarpit?->name ?? __('(deleted)'), 'why' => $hold->reason->describe()]) }}
        {{ match ($hold->outcome) {
            TarpitOutcome::Free => __('This was its free try :n, so it wasn\'t held.', ['n' => $hold->try]),
            TarpitOutcome::Held => __('Try :n: held :time at the edge.', ['n' => $hold->try, 'time' => $seconds]),
            TarpitOutcome::Full => __('Try :n, but the tarpit was holding all it may, so it was answered at once.', ['n' => $hold->try]),
            TarpitOutcome::Left => __('Try :n: the client gave up after :time.', ['n' => $hold->try, 'time' => $seconds]),
        } }}
        @if ($hold->tarpit !== null)
            <flux:link :href="route('tarpits.index')" wire:navigate class="ms-1">{{ __('Tarpits') }} →</flux:link>
        @endif
    </span>
</p>
