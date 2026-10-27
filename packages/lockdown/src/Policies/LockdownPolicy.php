<?php

namespace NiceTry\Lockdown\Policies;

use App\Enums\TeamPermission;
use App\Models\User;
use NiceTry\Lockdown\Models\Lockdown;

class LockdownPolicy
{
    /**
     * Determine whether the user can see the lockdown and what it covers.
     */
    public function view(User $user, Lockdown $lockdown): bool
    {
        return $user->belongsToTeam($lockdown->team);
    }

    /**
     * Determine whether the user can engage or lift the lockdown, change what it covers, change the
     * allowlist, and take a pass through it: whoever manages the team's domains (its admins and owner).
     */
    public function update(User $user, Lockdown $lockdown): bool
    {
        return $user->hasTeamPermission($lockdown->team, TeamPermission::ManageDomains);
    }
}
