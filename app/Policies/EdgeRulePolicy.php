<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\User;

class EdgeRulePolicy
{
    /**
     * Determine whether the user can see the team's rules.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can write rules for the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageRules);
    }

    /**
     * Determine whether the user can change the rule.
     */
    public function update(User $user, EdgeRule $edgeRule): bool
    {
        return $user->hasTeamPermission($edgeRule->team, TeamPermission::ManageRules);
    }

    /**
     * Determine whether the user can delete the rule.
     */
    public function delete(User $user, EdgeRule $edgeRule): bool
    {
        return $user->hasTeamPermission($edgeRule->team, TeamPermission::ManageRules);
    }
}
