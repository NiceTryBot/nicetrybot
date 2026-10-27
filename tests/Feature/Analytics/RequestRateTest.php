<?php

use App\Actions\Analytics\SummarizeRequestRate;
use App\Enums\StatsRange;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('it charts whole periods per second and per minute, leaving out the one under way', function () {
    $now = CarbonImmutable::parse('2026-10-02 15:30:07');
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();

    EdgeRequest::factory()->for($domain)->count(3)->create(['occurred_at' => $now->setTime(15, 29, 55)]);
    EdgeRequest::factory()->for($domain)->probe()->create(['occurred_at' => $now->setTime(15, 29, 51)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(15, 30, 5)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(13, 0)]);
    EdgeRequest::factory()->create(['occurred_at' => $now->setTime(15, 29, 55)]);

    $rates = app(SummarizeRequestRate::class)->handle($team, StatsRange::Hour, now: $now);

    expect($rates)
        ->lastMinute->toBe(5)
        ->requests->toBe(5)
        ->and($rates->perSecond->points)->toHaveCount(360)
        ->and(collect($rates->perSecond->points)->last())->toMatchArray(['label' => 'Oct 2, 15:29:50', 'requests' => 0.4, 'suspicious' => 0.1, 'peak' => 3])
        ->and($rates->perSecond->peak())->toBe(['requests' => 3, 'label' => 'Oct 2, 15:29:50'])
        ->and($rates->perMinute->hasPeak())->toBeFalse()
        ->and($rates->perMinute->points)->toHaveCount(60)
        ->and(collect($rates->perMinute->points)->last())->toMatchArray(['label' => 'Oct 2, 15:29', 'requests' => 4.0, 'peak' => 4]);
});

test('longer ranges average each period and keep its busiest minute', function () {
    $now = CarbonImmutable::parse('2026-10-02 15:30:07');
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();

    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(15, 21, 10)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(15, 21, 40)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => $now->setTime(15, 23)]);

    $perMinute = app(SummarizeRequestRate::class)->handle($team, StatsRange::Day, now: $now)->perMinute;

    expect($perMinute->hasPeak())->toBeTrue()
        ->and($perMinute->periodLabel())->toBe('5 minutes')
        ->and(collect($perMinute->points)->last())->toMatchArray(['label' => 'Oct 2, 15:25', 'peak' => 0])
        ->and($perMinute->points[count($perMinute->points) - 2])->toMatchArray(['label' => 'Oct 2, 15:20', 'requests' => 0.6, 'peak' => 2]);
});

test('the stats tab charts the team\'s request rate for the chosen range and domain', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.com']);
    $other = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'blog.example.com']);
    EdgeRequest::factory()->for($domain)->count(3)->create(['occurred_at' => now()->subSeconds(10)]);
    EdgeRequest::factory()->for($other)->create(['occurred_at' => now()->subSeconds(10)]);

    $this->actingAs($user)
        ->get(route('analytics.stats'))
        ->assertOk()
        ->assertSee('Requests per second')
        ->assertSee('Requests per minute');

    $this->actingAs($user);

    $stats = Livewire::test('pages::analytics.stats')
        ->assertSeeHtml('wire:poll.10s.visible');

    expect($stats->instance()->rates->lastMinute)->toBe(4);

    $stats->set('domain', 'shop.example.com')
        ->set('period', '7d')
        ->assertSee('one point every 30 minutes')
        ->assertSeeHtml('wire:poll.60s.visible');

    expect($stats->instance()->rates->lastMinute)->toBe(3);
});
