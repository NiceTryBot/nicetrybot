<?php

namespace NiceTry\Honeypots\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use NiceTry\Honeypots\Models\HoneypotTrap;

class HoneypotTrapPolicy
{
    /**
     * Determine whether the user can see the trap.
     */
    public function view(User $user, HoneypotTrap $trap): bool
    {
        return $user->belongsToTeam($trap->team);
    }

    /**
     * Determine whether the user can set up honeypots and traps for the team: whoever manages its
     * domains, since a trap changes what their sites answer.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can change the trap.
     */
    public function update(User $user, HoneypotTrap $trap): bool
    {
        return $user->hasTeamPermission($trap->team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can delete the trap.
     */
    public function delete(User $user, HoneypotTrap $trap): bool
    {
        return $user->hasTeamPermission($trap->team, TeamPermission::ManageDomains);
    }
}
