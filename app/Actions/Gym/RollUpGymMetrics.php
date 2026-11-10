<?php

namespace App\Actions\Gym;

use App\Enums\GymSessionStatus;
use App\Models\GymRoutineDailyMetric;
use App\Models\GymTeamDailyMetric;
use App\Models\Team;
use Carbon\CarbonImmutable;

class RollUpGymMetrics
{
    /**
     * Roll a single finished day's gym sessions up into the team and per-routine summary rows.
     *
     * A day is keyed by the session's started_at. For a finished day every session that is still
     * training has gone quiet, so it counts as given up — the gym page only needs the idle check
     * for today, which it still computes live.
     */
    public function handle(Team $team, CarbonImmutable $day): void
    {
        $start = $day->startOfDay();
        $end = $start->addDay();

        $totals = $team->gymSessions()
            ->real()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->toBase()
            ->selectRaw('count(*) as entered')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cleared', [GymSessionStatus::Cleared->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as turned_away', [GymSessionStatus::TurnedAway->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as gave_up', [GymSessionStatus::Training->value])
            ->selectRaw('coalesce(sum(hashes), 0) as hashes')
            ->first();

        if ((int) $totals->entered > 0) {
            GymTeamDailyMetric::updateOrCreate(
                ['team_id' => $team->id, 'day' => $start],
                [
                    'entered' => (int) $totals->entered,
                    'cleared' => (int) $totals->cleared,
                    'turned_away' => (int) $totals->turned_away,
                    'gave_up' => (int) $totals->gave_up,
                    'hashes' => (int) $totals->hashes,
                ],
            );
        } else {
            GymTeamDailyMetric::query()
                ->where('team_id', $team->id)
                ->where('day', $start)
                ->delete();
        }

        $perRoutine = $team->gymSessions()
            ->real()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->join('gym_routine_gym_session as pivot', 'pivot.gym_session_id', '=', 'gym_sessions.id')
            ->toBase()
            ->groupBy('pivot.gym_routine_id')
            ->selectRaw('pivot.gym_routine_id as gym_routine_id')
            ->selectRaw('count(*) as entered')
            ->selectRaw('sum(case when gym_sessions.status = ? then 1 else 0 end) as cleared', [GymSessionStatus::Cleared->value])
            ->get();

        foreach ($perRoutine as $routine) {
            GymRoutineDailyMetric::updateOrCreate(
                ['gym_routine_id' => (int) $routine->gym_routine_id, 'day' => $start],
                [
                    'team_id' => $team->id,
                    'entered' => (int) $routine->entered,
                    'cleared' => (int) $routine->cleared,
                ],
            );
        }

        $keep = $perRoutine->pluck('gym_routine_id')->map(fn ($id) => (int) $id)->all();

        GymRoutineDailyMetric::query()
            ->where('team_id', $team->id)
            ->where('day', $start)
            ->whereNotIn('gym_routine_id', $keep)
            ->delete();
    }

    /**
     * Roll up the finished days that fall in the gym page's window, newest first so a run cut short
     * still leaves the most-visible days fresh. Today is left to the page to count live.
     */
    public function backfill(Team $team, int $days, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        for ($ago = 1; $ago <= $days; $ago++) {
            $this->handle($team, $now->subDays($ago));
        }
    }
}
