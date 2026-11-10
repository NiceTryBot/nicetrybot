<?php

use App\Actions\Gym\RollUpGymMetrics;
use App\Enums\GymSessionStatus;
use App\Models\GymRoutine;
use App\Models\GymRoutineDailyMetric;
use App\Models\GymSession;
use App\Models\GymTeamDailyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;

test('rolling up a finished day totals its sessions by team and routine', function () {
    $team = User::factory()->create()->currentTeam;
    $routine = GymRoutine::factory()->for($team)->create();
    $day = CarbonImmutable::parse('2026-11-04 00:00:00');

    $enter = function (array $attributes) use ($team, $routine, $day) {
        $session = GymSession::factory()->for($team)->create([...['started_at' => $day->setTime(9, 0), 'last_seen_at' => $day->setTime(9, 0)], ...$attributes]);
        $session->routines()->attach($routine);

        return $session;
    };

    $enter(['status' => GymSessionStatus::Cleared, 'hashes' => 1_000]);
    $enter(['status' => GymSessionStatus::Cleared, 'hashes' => 500]);
    $enter(['status' => GymSessionStatus::TurnedAway]);
    $enter(['status' => GymSessionStatus::Training]); // A finished day's still-training session has given up.

    // An admin's preview and another day's session both stay out of this day's roll-up.
    GymSession::factory()->for($team)->preview()->create(['started_at' => $day->setTime(9, 0), 'status' => GymSessionStatus::Cleared, 'hashes' => 9_999]);
    GymSession::factory()->for($team)->cleared()->create(['started_at' => $day->subDay()->setTime(9, 0)]);

    app(RollUpGymMetrics::class)->handle($team, $day);

    $teamMetric = GymTeamDailyMetric::where('team_id', $team->id)->whereDate('day', '2026-11-04')->sole();
    expect($teamMetric->entered)->toBe(4)
        ->and($teamMetric->cleared)->toBe(2)
        ->and($teamMetric->turned_away)->toBe(1)
        ->and($teamMetric->gave_up)->toBe(1)
        ->and($teamMetric->hashes)->toBe(1_500);

    $routineMetric = GymRoutineDailyMetric::where('gym_routine_id', $routine->id)->whereDate('day', '2026-11-04')->sole();
    expect($routineMetric->entered)->toBe(4)->and($routineMetric->cleared)->toBe(2);
});

test('re-rolling a day replaces its metrics and clears a routine that no longer applies', function () {
    $team = User::factory()->create()->currentTeam;
    $routine = GymRoutine::factory()->for($team)->create();
    $day = CarbonImmutable::parse('2026-11-04 00:00:00');

    $session = GymSession::factory()->for($team)->cleared()->create(['started_at' => $day->setTime(9, 0), 'hashes' => 100]);
    $session->routines()->attach($routine);

    app(RollUpGymMetrics::class)->handle($team, $day);
    app(RollUpGymMetrics::class)->handle($team, $day); // Idempotent: a second run leaves one row, not two.

    expect(GymTeamDailyMetric::where('team_id', $team->id)->count())->toBe(1)
        ->and(GymRoutineDailyMetric::where('gym_routine_id', $routine->id)->count())->toBe(1);

    // The session drops the routine, so re-rolling removes its stale metric row.
    $session->routines()->detach($routine);
    app(RollUpGymMetrics::class)->handle($team, $day);

    expect(GymRoutineDailyMetric::where('gym_routine_id', $routine->id)->count())->toBe(0)
        ->and(GymTeamDailyMetric::where('team_id', $team->id)->whereDate('day', '2026-11-04')->sole()->entered)->toBe(1);
});

test('the command rolls up the recent finished days for every team with sessions', function () {
    $team = User::factory()->create()->currentTeam;
    GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->subDays(2)->setTime(10, 0)]);
    GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->subDays(1)->setTime(10, 0)]);
    // Today's session is left for the page to count live, never rolled up.
    GymSession::factory()->for($team)->cleared()->create(['started_at' => today()->setTime(10, 0)]);

    $this->artisan('gym:roll-up-metrics', ['--days' => 3])->assertSuccessful();

    expect(GymTeamDailyMetric::where('team_id', $team->id)->count())->toBe(2)
        ->and(GymTeamDailyMetric::where('team_id', $team->id)->whereDate('day', today()->toDateString())->exists())->toBeFalse();
});
