@props([
    'kind',
    'value' => null,
    'display' => null,
])

{{-- One value (an IP, TLS fingerprint or user agent) as the team knows it: its label, or itself. An IP inside a crawler's published ranges also carries that crawler's name. --}}
@php
    $teamId = auth()->user()?->currentTeam?->id;
    $kind = $kind instanceof \App\Enums\LabelKind ? $kind : \App\Enums\LabelKind::from($kind);
    $clientLabel = $teamId === null ? null : app(\App\Data\LabelDirectory::class)->labelFor($teamId, $kind, $value);
    $crawler = $clientLabel === null && $kind === \App\Enums\LabelKind::Ip ? app(\App\Data\CrawlerDirectory::class)->crawlerFor($value) : null;
@endphp

@if ($clientLabel)
    <span {{ $attributes->class('font-sans font-semibold text-ink dark:text-white') }} title="{{ $value }}" data-test="client-label">{{ $clientLabel->name }}</span>
@elseif ($crawler)
    <span {{ $attributes }}>{{ $display ?? $value }}</span>
    <flux:badge size="sm" color="sky" icon="check-badge" class="ms-1 font-sans" title="{{ __('Verified: this IP is in the ranges :crawler publishes.', ['crawler' => $crawler->label()]) }}" data-test="crawler-label">{{ $crawler->label() }}</flux:badge>
@else
    <span {{ $attributes }}>{{ $display ?? $value ?? '—' }}</span>
@endif
