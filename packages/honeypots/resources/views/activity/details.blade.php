@use('NiceTry\Honeypots\Enums\HoneypotOutcome')
@php($seconds = \Illuminate\Support\Number::format($hit->took_ms / 1000, maxPrecision: 1).' s')
<p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-test="honeypot-details">
    <flux:icon.bug-ant variant="micro" class="mt-0.5 size-3.5 shrink-0" />
    <span>
        @if ($hit->outcome === HoneypotOutcome::Served)
            {{ __('The site answered 404, so the honeypot :name answered instead, with a :status in :time.', ['name' => $hit->honeypot?->name ?? __('(deleted)'), 'status' => $hit->status, 'time' => $seconds]) }}
        @else
            {{ __('The site answered 404 and the honeypot :name didn’t answer within :time, so the site’s own 404 went out.', ['name' => $hit->honeypot?->name ?? __('(deleted)'), 'time' => $seconds]) }}
        @endif
        @if ($hit->trap !== null)
            {{ __('Trap: :patterns.', ['patterns' => implode(', ', $hit->trap->patterns)]) }}
        @endif
        @if ($hit->honeypot !== null)
            <flux:link :href="route('honeypots.index')" wire:navigate class="ms-1">{{ __('Honeypots') }} →</flux:link>
        @endif
    </span>
</p>
