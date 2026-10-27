<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum TrafficPeriod: string
{
    case Minute = '1m';
    case Hour = '1h';
    case TwoHours = '2h';
    case FourHours = '4h';
    case SixHours = '6h';
    case EightHours = '8h';
    case SixteenHours = '16h';
    case Day = '24h';
    case ThirtySixHours = '36h';
    case TwoDays = '48h';
    case ThreeDays = '3d';
    case Week = '7d';
    case TwoWeeks = '14d';
    case ThreeWeeks = '21d';
    case Month = '30d';

    /**
     * Get the display label for the period.
     */
    public function label(): string
    {
        return match ($this) {
            self::Minute => __('Last minute'),
            self::Hour => __('Last hour'),
            self::Week => __('Last 7 days'),
            self::ThreeDays => __('Last 3 days'),
            self::TwoWeeks => __('Last 14 days'),
            self::ThreeWeeks => __('Last 21 days'),
            self::Month => __('Last 30 days'),
            default => __('Last :count hours', ['count' => $this->hours()]),
        };
    }

    /**
     * Get the label used when comparing with the previous period.
     */
    public function comparisonLabel(): string
    {
        return match ($this) {
            self::Minute => __('vs. previous minute'),
            self::Hour => __('vs. previous hour'),
            self::Day => __('vs. previous day'),
            self::Week => __('vs. previous week'),
            self::ThreeDays => __('vs. previous 3 days'),
            self::TwoWeeks => __('vs. previous 14 days'),
            self::ThreeWeeks => __('vs. previous 21 days'),
            self::Month => __('vs. previous 30 days'),
            default => __('vs. previous :count hours', ['count' => $this->hours()]),
        };
    }

    /**
     * Get how many seconds each chart bucket covers.
     */
    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Minute => 1,
            self::Hour => 60,
            self::TwoHours => 300,
            self::FourHours => 600,
            self::SixHours => 900,
            self::EightHours => 1_200,
            self::SixteenHours, self::Day => 3_600,
            self::ThirtySixHours, self::TwoDays => 7_200,
            self::ThreeDays => 10_800,
            self::Week, self::TwoWeeks, self::ThreeWeeks, self::Month => 86_400,
        };
    }

    /**
     * Get the number of chart buckets in the period.
     */
    public function bucketCount(): int
    {
        return match ($this) {
            self::Minute, self::Hour => 60,
            self::SixteenHours => 16,
            self::ThirtySixHours => 18,
            self::Week => 7,
            self::TwoWeeks => 14,
            self::ThreeWeeks => 21,
            self::Month => 30,
            default => 24,
        };
    }

    /**
     * Get how many seconds the period covers.
     */
    public function seconds(): int
    {
        return $this->bucketSeconds() * $this->bucketCount();
    }

    /**
     * How long counts over the period stay fresh, then how long they may be served stale while they're
     * counted again: a day or less moves by the minute, a week or a month hardly in five.
     *
     * @return array{0: int, 1: int}
     */
    public function cacheSeconds(): array
    {
        return $this->seconds() > 86_400 ? [300, 1_800] : [60, 300];
    }

    /**
     * Get the date format for a chart bucket's label.
     */
    public function bucketLabelFormat(): string
    {
        return match (true) {
            $this === self::Minute => 'H:i:s',
            $this->bucketSeconds() < 3_600 => 'H:i',
            $this->seconds() <= 86_400 => 'H:00',
            $this->bucketSeconds() < 86_400 => 'D H:00',
            default => 'M j',
        };
    }

    /**
     * Get the start of the first bucket in the period.
     */
    public function start(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $bucket = $this->bucketSeconds();

        if ($bucket >= 86_400) {
            return $now->startOfDay()->subDays($this->bucketCount() - 1);
        }

        return $now->startOfSecond()
            ->subSeconds($now->getTimestamp() % $bucket)
            ->subSeconds(($this->bucketCount() - 1) * $bucket);
    }

    /**
     * Get the start of the equally long period before this one.
     */
    public function previousStart(?CarbonImmutable $now = null): CarbonImmutable
    {
        $start = $this->start($now);

        return $this->bucketSeconds() >= 86_400
            ? $start->subDays($this->bucketCount())
            : $start->subSeconds($this->seconds());
    }

    /**
     * Get how many hours the period covers.
     */
    private function hours(): int
    {
        return intdiv($this->seconds(), 3_600);
    }
}
