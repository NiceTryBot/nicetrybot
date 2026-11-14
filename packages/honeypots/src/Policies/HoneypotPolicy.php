<?php

namespace NiceTry\Honeypots\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use NiceTry\Honeypots\Models\Honeypot;

class HoneypotPolicy
{
    /**
     * Determine whether the user can see the honeypot.
     */
    public function view(User $user, Honeypot $honeypot): bool
    {
        return $user->belongsToTeam($honeypot->team);
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
     * Determine whether the user can change the honeypot.
     */
    public function update(User $user, Honeypot $honeypot): bool
    {
        return $user->hasTeamPermission($honeypot->team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can delete the honeypot.
     */
    public function delete(User $user, Honeypot $honeypot): bool
    {
        return $user->hasTeamPermission($honeypot->team, TeamPermission::ManageDomains);
    }
}
