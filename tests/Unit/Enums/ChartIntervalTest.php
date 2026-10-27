<?php

use App\Enums\ChartInterval;
use App\Enums\TrafficPeriod;
use Carbon\CarbonImmutable;

test('each period starts one unit finer than it\'s told in', function (TrafficPeriod $period, ChartInterval $interval) {
    expect(ChartInterval::default($period))->toBe($interval);
})->with([
    'a minute shows seconds' => [TrafficPeriod::Minute, ChartInterval::Second],
    'an hour shows minutes' => [TrafficPeriod::Hour, ChartInterval::Minute],
    'a day shows hours' => [TrafficPeriod::Day, ChartInterval::Hour],
    'a week of days shows hours' => [TrafficPeriod::Week, ChartInterval::Hour],
    'two weeks show quarter days' => [TrafficPeriod::TwoWeeks, ChartInterval::SixHours],
    'three weeks show quarter days' => [TrafficPeriod::ThreeWeeks, ChartInterval::SixHours],
    'a month shows days' => [TrafficPeriod::Month, ChartInterval::Day],
]);

test('a period offers the intervals that draw it with 2 to 400 bars', function () {
    expect(ChartInterval::for(TrafficPeriod::Day))->toBe([ChartInterval::FiveMinutes, ChartInterval::FifteenMinutes, ChartInterval::Hour, ChartInterval::SixHours])
        ->and(ChartInterval::for(TrafficPeriod::Month))->toBe([ChartInterval::SixHours, ChartInterval::Day, ChartInterval::Week]);
});

test('a choice the period doesn\'t offer falls back to its default', function () {
    expect(ChartInterval::resolve('1d', TrafficPeriod::Week))->toBe(ChartInterval::Day)
        ->and(ChartInterval::resolve('1s', TrafficPeriod::Week))->toBe(ChartInterval::Hour)
        ->and(ChartInterval::resolve('nonsense', TrafficPeriod::Hour))->toBe(ChartInterval::Minute);
});

test('bars line up on the hour, the day, and weeks starting Monday', function () {
    $moment = CarbonImmutable::parse('2026-10-14 12:34:56', 'UTC');

    expect(ChartInterval::Hour->floor($moment)->toDateTimeString())->toBe('2026-10-14 12:00:00')
        ->and(ChartInterval::FifteenMinutes->floor($moment)->toDateTimeString())->toBe('2026-10-14 12:30:00')
        ->and(ChartInterval::Day->floor($moment)->toDateTimeString())->toBe('2026-10-14 00:00:00')
        ->and(ChartInterval::Week->floor($moment)->toDateTimeString())->toBe('2026-10-12 00:00:00');
});

test('every period starts on an interval it offers', function (TrafficPeriod $period) {
    expect(ChartInterval::for($period))->toContain(ChartInterval::default($period));
})->with(TrafficPeriod::cases());
