<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How much time each bar of a traffic chart covers. A period offers the intervals that draw it with a
 * useful number of bars, and starts one unit finer than it's told in, so a week shows its hours.
 */
enum ChartInterval: string
{
    case Second = '1s';
    case Minute = '1m';
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case Hour = '1h';
    case SixHours = '6h';
    case Day = '1d';
    case Week = '1w';

    /**
     * The fewest and most bars an interval may draw a period with.
     */
    private const int MIN_BARS = 2;

    private const int MAX_BARS = 400;

    /**
     * Get the intervals that draw a period with a useful number of bars, finest first.
     *
     * @return list<self>
     */
    public static function for(TrafficPeriod $period): array
    {
        return array_values(array_filter(self::cases(), function (self $interval) use ($period) {
            $bars = $period->seconds() / $interval->seconds();

            return $bars >= self::MIN_BARS && $bars <= self::MAX_BARS;
        }));
    }

    /**
     * Get the interval a period starts on: one unit finer than the period is told in. A minute shows its
     * seconds, an hour its minutes, a day or a week of days their hours, two or three
     * weeks their quarter days (hourly bars would be too thin), and a month its days.
     */
    public static function default(TrafficPeriod $period): self
    {
        return match ($period) {
            TrafficPeriod::Minute => self::Second,
            TrafficPeriod::Hour, TrafficPeriod::TwoHours => self::Minute,
            TrafficPeriod::FourHours, TrafficPeriod::SixHours => self::FiveMinutes,
            TrafficPeriod::EightHours, TrafficPeriod::SixteenHours => self::FifteenMinutes,
            TrafficPeriod::Day, TrafficPeriod::ThirtySixHours, TrafficPeriod::TwoDays, TrafficPeriod::ThreeDays, TrafficPeriod::Week => self::Hour,
            TrafficPeriod::TwoWeeks, TrafficPeriod::ThreeWeeks => self::SixHours,
            TrafficPeriod::Month => self::Day,
        };
    }

    /**
     * Get the interval a choice names, if the period offers it, or the period's default.
     */
    public static function resolve(string $choice, TrafficPeriod $period): self
    {
        $interval = self::tryFrom($choice);

        return in_array($interval, self::for($period), true) ? $interval : self::default($period);
    }

    /**
     * Get how many seconds one bar covers.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::FiveMinutes => 300,
            self::FifteenMinutes => 900,
            self::Hour => 3_600,
            self::SixHours => 21_600,
            self::Day => 86_400,
            self::Week => 604_800,
        };
    }

    /**
     * Get the seconds after the Unix epoch the bars line up with: weeks start on a Monday (the epoch
     * was a Thursday), everything else on the epoch.
     */
    public function offsetSeconds(): int
    {
        return $this === self::Week ? 4 * 86_400 : 0;
    }

    /**
     * Get the start of the bar a moment falls in.
     */
    public function floor(CarbonImmutable $moment): CarbonImmutable
    {
        $timestamp = $moment->getTimestamp();

        return $moment->setTimestamp($timestamp - (($timestamp - $this->offsetSeconds()) % $this->seconds()));
    }

    /**
     * Get the date format for a bar's label. Bars under a day name their day too once the period is
     * longer than one.
     */
    public function labelFormat(TrafficPeriod $period): string
    {
        return match (true) {
            $this === self::Second => 'H:i:s',
            $this->seconds() < 86_400 => $period->seconds() > 86_400 ? 'M j, H:i' : 'H:i',
            default => 'M j',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Second => __('By second'),
            self::Minute => __('By minute'),
            self::FiveMinutes => __('By 5 minutes'),
            self::FifteenMinutes => __('By 15 minutes'),
            self::Hour => __('By hour'),
            self::SixHours => __('By 6 hours'),
            self::Day => __('By day'),
            self::Week => __('By week'),
        };
    }
}
