<?php

namespace App\Enums;

enum StatsRange: string
{
    case FifteenMinutes = '15m';
    case Hour = '1h';
    case ThreeHours = '3h';
    case TwelveHours = '12h';
    case Day = '24h';
    case Week = '7d';

    /**
     * Get the short label shown on the range picker, CloudWatch style.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::FifteenMinutes => '15m',
            self::Hour => '1h',
            self::ThreeHours => '3h',
            self::TwelveHours => '12h',
            self::Day => '1d',
            self::Week => '1w',
        };
    }

    /**
     * Get how many seconds the range reaches back.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::FifteenMinutes => 900,
            self::Hour => 3_600,
            self::ThreeHours => 10_800,
            self::TwelveHours => 43_200,
            self::Day => 86_400,
            self::Week => 604_800,
        };
    }

    /**
     * Get the seconds each point on the requests-per-second chart covers.
     */
    public function perSecondPeriod(): int
    {
        return match ($this) {
            self::FifteenMinutes => 5,
            self::Hour => 10,
            self::ThreeHours => 30,
            self::TwelveHours => 120,
            self::Day => 300,
            self::Week => 1_800,
        };
    }

    /**
     * Get the seconds each point on the requests-per-minute chart covers.
     */
    public function perMinutePeriod(): int
    {
        return match ($this) {
            self::FifteenMinutes, self::Hour, self::ThreeHours => 60,
            self::TwelveHours, self::Day => 300,
            self::Week => 3_600,
        };
    }

    /**
     * Get how often the page refreshes while it's open, in seconds.
     */
    public function refreshSeconds(): int
    {
        return match ($this) {
            self::FifteenMinutes, self::Hour => 10,
            self::ThreeHours, self::TwelveHours => 30,
            self::Day, self::Week => 60,
        };
    }
}
