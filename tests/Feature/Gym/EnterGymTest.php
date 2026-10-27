<?php

use App\Actions\Gym\EnterGym;
use App\Data\GymVisitor;
use App\Enums\GymSessionStatus;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\Verdict;

test('a visitor enters every enabled routine they match, in stacking order', function () {
    $team = Team::factory()->create();
    $datacenter = GymRoutine::factory()->for($team)->entering([['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']]])->create(['position' => 2]);
    $scripts = GymRoutine::factory()->for($team)->entering([['field' => 'ua', 'operator' => 'contains', 'values' => ['python']]])->create(['position' => 1]);
    GymRoutine::factory()->for($team)->entering([['field' => 'country', 'operator' => 'in', 'values' => ['FR']]])->create();
    GymRoutine::factory()->for($team)->disabled()->entering([])->create();

    $session = app(EnterGym::class)->handle($team, new GymVisitor('203.0.113.7', userAgent: 'python-requests/2.32', asn: 24940), 'https://shop.example.com/');

    expect($session->routines()->orderBy('position')->pluck('gym_routines.id')->all())->toBe([$scripts->id, $datacenter->id])
        ->and($session)
        ->status->toBe(GymSessionStatus::Training)
        ->destination->toBe('https://shop.example.com/')
        ->client_ip->toBe('203.0.113.7');
});

test('a visitor holding a verdict from a rule enters the routine that asks for it', function () {
    $team = Team::factory()->create();
    $rule = EdgeRule::factory()->for($team)->create();
    Verdict::factory()->for($rule, 'rule')->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7']);
    $routine = GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create();

    $flagged = app(EnterGym::class)->handle($team, new GymVisitor('203.0.113.7'));
    $clean = app(EnterGym::class)->handle($team, new GymVisitor('198.51.100.4'));

    expect($flagged->routines->modelKeys())->toBe([$routine->id])
        ->and($clean)->toBeNull();
});

test('another team\'s routines never pull in a visitor', function () {
    GymRoutine::factory()->entering([])->create();

    expect(app(EnterGym::class)->handle(Team::factory()->create(), new GymVisitor('203.0.113.7')))->toBeNull();
});
