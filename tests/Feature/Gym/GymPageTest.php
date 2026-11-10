<?php

use App\Actions\Gym\RollUpGymMetrics;
use App\Enums\GymSessionStatus;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\GymTeamDailyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('the activity chart bars read finished days from the rollup and count today live', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    // A finished day two days back lives in the rollup.
    GymTeamDailyMetric::create([
        'team_id' => $team->id,
        'day' => today()->subDays(2),
        'entered' => 12, 'cleared' => 5, 'turned_away' => 2, 'gave_up' => 1, 'hashes' => 9_000,
    ]);

    // Today is counted live: two visitors, one of them cleared, 150 hashes between them.
    GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->setTime(9, 0), 'hashes' => 100]);
    GymSession::factory()->for($team)->create(['started_at' => today()->setTime(10, 0), 'hashes' => 50]);

    $this->actingAs($user);
    $component = Livewire::test('pages::gym.index');

    $entered = collect($component->instance()->chartBuckets)->keyBy('key');
    expect($entered[today()->subDays(2)->toDateString()]['count'])->toBe(12)
        ->and($entered[today()->toDateString()]['count'])->toBe(2);

    $cleared = collect($component->set('chartSource', 'cleared')->instance()->chartBuckets)->keyBy('key');
    expect($cleared[today()->subDays(2)->toDateString()]['count'])->toBe(5)
        ->and($cleared[today()->toDateString()]['count'])->toBe(1);

    $hashes = collect($component->set('chartSource', 'hashes')->instance()->chartBuckets)->keyBy('key');
    expect($hashes[today()->toDateString()]['count'])->toBe(150);
});

test('the gym lists routines in stacking order with what each one does', function () {
    $user = User::factory()->create();
    GymRoutine::factory()->for($user->currentTeam)->obstacles(['waiting-room' => []])->create(['name' => 'Second', 'position' => 2]);
    GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'First', 'position' => 1]);
    GymRoutine::factory()->create(['name' => 'Another team\'s routine']);
    $this->actingAs($user);

    $this->get(route('gym.index'))
        ->assertSeeInOrder(['First', 'Visitors whose network is DigitalOcean or Hetzner.', 'Proof of work', 'Second', 'Waiting room'])
        ->assertDontSee('Another team\'s routine');
});

test('dragging a routine changes the stacking order', function () {
    $user = User::factory()->create();
    [$first, $second, $third] = GymRoutine::factory()->for($user->currentTeam)->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $this->actingAs($user);

    Livewire::test('pages::gym.index')->call('sortRoutine', $third->id, 0);

    expect($user->currentTeam->gymRoutines()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id]);
});

test('the numbers count real visits only, and a stale visit has given up', function () {
    $user = User::factory()->create();
    GymSession::factory()->for($user->currentTeam)->cleared()->create(['hashes' => 3_000_000]);
    GymSession::factory()->for($user->currentTeam)->create(['last_seen_at' => now()->subHour()]);
    GymSession::factory()->for($user->currentTeam)->create(['status' => GymSessionStatus::TurnedAway]);
    GymSession::factory()->for($user->currentTeam)->preview()->cleared()->create(['hashes' => 9_000_000]);
    $this->actingAs($user);

    $stats = Livewire::test('pages::gym.index')->instance()->stats;

    expect($stats)->toBe(['entered' => 3, 'cleared' => 1, 'turned_away' => 1, 'gave_up' => 1, 'hashes' => 3_000_000]);
});

test('the numbers add finished days from the roll-up to today counted live', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $routine = GymRoutine::factory()->for($team)->create(['name' => 'Probers']);

    $attach = function (GymSession $session) use ($routine) {
        $session->routines()->attach($routine);

        return $session;
    };

    // Two days ago: one cleared, one turned away — rolled into the summary tables, off the live scan.
    $attach(GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->subDays(2)->setTime(9, 0), 'hashes' => 2_000]));
    $attach(GymSession::factory()->for($team)->create(['started_at' => today()->subDays(2)->setTime(9, 0), 'status' => GymSessionStatus::TurnedAway]));
    app(RollUpGymMetrics::class)->handle($team, CarbonImmutable::parse(today()->subDays(2)->toDateString()));

    // Today: one cleared, counted live rather than from the roll-up.
    $attach(GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->setTime(8, 0), 'hashes' => 500]));

    $this->actingAs($user);

    $component = Livewire::test('pages::gym.index');

    $routineRow = $component->instance()->routines->firstWhere('id', $routine->id);
    expect($routineRow->entered_count)->toBe(3)->and($routineRow->cleared_count)->toBe(2);

    expect($component->instance()->stats)->toBe(['entered' => 3, 'cleared' => 2, 'turned_away' => 1, 'gave_up' => 0, 'hashes' => 2_500]);
});

test('the tester shows which routines a visitor would stack into', function () {
    $user = User::factory()->create();
    GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Datacenter waiting room']);
    GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'country', 'operator' => 'in', 'values' => ['FR']]])->create(['name' => 'French visitors']);
    $this->actingAs($user);

    Livewire::test('pages::gym.index')
        ->set('testIp', '203.0.113.7')
        ->set('testAsn', 14061)
        ->assertSee('Enters 1 routine:')
        ->assertSee('Datacenter waiting room')
        ->set('testAsn', null)
        ->assertSee('No routine wants this visitor.');
});

test('members can switch nothing on or off', function () {
    $member = User::factory()->create();
    $routine = GymRoutine::factory()->create();
    $routine->team->members()->attach($member, ['role' => 'member']);
    $member->switchTeam($routine->team);
    $this->actingAs($member);

    Livewire::test('pages::gym.index')
        ->assertDontSee('New routine')
        ->call('toggle', $routine->id)
        ->assertForbidden();

    expect($routine->fresh()->enabled)->toBeTrue();
});
