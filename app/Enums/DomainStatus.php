<?php

namespace App\Enums;

enum DomainStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Paused = 'paused';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => __('Awaiting verification'),
            self::Active => __('Live'),
            self::Paused => __('Paused'),
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::PendingVerification => 'amber',
            self::Active => 'emerald',
            self::Paused => 'zinc',
        };
    }
}
