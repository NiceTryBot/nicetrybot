<?php

use App\Actions\Analytics\SummarizeTraffic;
use App\Enums\RequestAction;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Carbon\CarbonImmutable;

test('it totals the period, compares it with the previous one, and fills hourly buckets', function () {
    $now = CarbonImmutable::parse('2026-10-02 15:30:00');
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();

    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->subMinutes(10)]);
    EdgeRequest::factory()->for($domain)->probe()->create(['occurred_at' => $now->subMinutes(20)]);
    EdgeRequest::factory()->for($domain)->probe(RequestAction::Diverted)->create(['occurred_at' => $now->subHours(3), 'status_code' => 200, 'duration_ms' => 120_000]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->subHours(30)]);
    EdgeRequest::factory()->create(['occurred_at' => $now->subMinutes(5)]);

    $summary = app(SummarizeTraffic::class)->handle($team, TrafficPeriod::Day, now: $now);

    expect($summary)
        ->requests->toBe(3)
        ->suspicious->toBe(2)
        ->notFound->toBe(1)
        ->keptOffOrigin->toBe(1)
        ->previousRequests->toBe(1)
        ->and($summary->gymTime())->toBe('0h 2m')
        ->and($summary->change($summary->requests, $summary->previousRequests))->toBe(200.0)
        ->and($summary->buckets)->toHaveCount(24)
        ->and(collect($summary->buckets)->last())->toMatchArray(['label' => '15:00', 'humans' => 1, 'suspicious' => 1, 'notFound' => 1]);
});

test('the last hour is charted minute by minute and compared with the hour before', function () {
    $now = CarbonImmutable::parse('2026-10-02 15:30:20');
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();

    EdgeRequest::factory()->for($domain)->count(2)->create(['occurred_at' => $now->subSeconds(5)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(14, 31)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(14, 30, 59)]);

    $summary = app(SummarizeTraffic::class)->handle($team, TrafficPeriod::Hour, now: $now);

    expect($summary)
        ->requests->toBe(3)
        ->previousRequests->toBe(1)
        ->and($summary->buckets)->toHaveCount(60)
        ->and($summary->buckets[0])->toMatchArray(['label' => '14:31', 'humans' => 1])
        ->and(collect($summary->buckets)->last())->toMatchArray(['label' => '15:30', 'humans' => 2]);
});

test('the last minute is charted second by second', function () {
    $now = CarbonImmutable::parse('2026-10-02 15:30:20');
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();

    EdgeRequest::factory()->for($domain)->probe()->create(['occurred_at' => $now->subSeconds(3)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->subMinutes(2)]);

    $summary = app(SummarizeTraffic::class)->handle($team, TrafficPeriod::Minute, now: $now);

    expect($summary)
        ->requests->toBe(1)
        ->and($summary->buckets)->toHaveCount(60)
        ->and($summary->buckets[56])->toMatchArray(['label' => '15:30:17', 'suspicious' => 1]);
});

test('it limits the summary to one domain when given', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();
    EdgeRequest::factory()->for($domain)->create();
    EdgeRequest::factory()->for(Domain::factory()->for($team))->create();

    $summary = app(SummarizeTraffic::class)->handle($team, TrafficPeriod::Day, $domain);

    expect($summary->requests)->toBe(1);
});
