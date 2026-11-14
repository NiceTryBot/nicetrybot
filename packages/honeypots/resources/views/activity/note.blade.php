@use('NiceTry\Honeypots\Enums\HoneypotOutcome')
<span class="mt-1 flex items-center gap-1 text-[0.65rem] whitespace-nowrap text-zinc-500 dark:text-zinc-400" data-test="honeypot-outcome">
    <span @class([
        'size-1.5 shrink-0 rounded-full',
        'bg-amber-500' => $hit->outcome === HoneypotOutcome::Served,
        'bg-zinc-400' => $hit->outcome === HoneypotOutcome::Failed,
    ])></span>
    @if ($hit->outcome === HoneypotOutcome::Served)
        {{ __('Served :status', ['status' => $hit->status]) }} · {{ \Illuminate\Support\Number::format($hit->took_ms / 1000, maxPrecision: 1) }} s
    @else
        {{ __('No answer, site’s 404') }}
    @endif
</span>
