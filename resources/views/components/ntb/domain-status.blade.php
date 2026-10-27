@props(['status'])

<flux:badge size="sm" :color="$status->color()" {{ $attributes }}>
    <span @class([
        'me-1.5 inline-block size-1.5 rounded-full',
        'bg-emerald-500 animate-pulse' => $status === \App\Enums\DomainStatus::Active,
        'bg-amber-500' => $status === \App\Enums\DomainStatus::PendingVerification,
        'bg-zinc-400' => $status === \App\Enums\DomainStatus::Paused,
    ])></span>
    {{ $status->label() }}
</flux:badge>
