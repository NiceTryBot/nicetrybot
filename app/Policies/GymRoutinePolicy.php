<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;

class GymRoutinePolicy
{
    /**
     * Determine whether the user can see the team's gym.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can build routines for the team's gym.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGym);
    }

    /**
     * Determine whether the user can change the routine.
     */
    public function update(User $user, GymRoutine $gymRoutine): bool
    {
        return $user->hasTeamPermission($gymRoutine->team, TeamPermission::ManageGym);
    }

    /**
     * Determine whether the user can delete the routine.
     */
    public function delete(User $user, GymRoutine $gymRoutine): bool
    {
        return $user->hasTeamPermission($gymRoutine->team, TeamPermission::ManageGym);
    }
}
