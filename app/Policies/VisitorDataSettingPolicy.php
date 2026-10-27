<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;

class VisitorDataSettingPolicy
{
    /**
     * Determine whether the user can see how long the team keeps visitors' data, and look visitors up.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can change how long the team keeps visitors' data and who visitors
     * contact, lift a visitor's verdicts and erase a visitor's data.
     */
    public function manage(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageVisitorData);
    }
}
