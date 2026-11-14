@props(['destinations' => []])

{{-- A rule whose clients get a JS fingerprint where it sends them (App\Support\RuleDestination::$fingerprints). --}}
@php($fingerprintedBy = array_values(array_unique(array_map(fn ($destination) => $destination->label, array_filter($destinations, fn ($destination) => $destination->fingerprints)))))

@if ($fingerprintedBy !== [])
    <span
        {{ $attributes->class('inline-flex shrink-0 items-center gap-0.5 rounded-md bg-teal-50 px-1 py-0.5 text-[0.65rem] font-medium text-teal-700 dark:bg-teal-500/10 dark:text-teal-300') }}
        title="{{ __('JS fingerprinted by :where', ['where' => implode(', ', $fingerprintedBy)]) }}"
        data-test="rule-fingerprint-mark"
    >
        <flux:icon.finger-print variant="micro" class="size-3" />
        <span>{{ __('JS') }}</span>
    </span>
@endif
