<?php

namespace NiceTry\Rewrites\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use NiceTry\Rewrites\Models\ResponseRewrite;

class ResponseRewritePolicy
{
    /**
     * Determine whether the user can see the rewrite.
     */
    public function view(User $user, ResponseRewrite $rewrite): bool
    {
        return $user->belongsToTeam($rewrite->team);
    }

    /**
     * Determine whether the user can set up rewrites for the team: whoever manages its domains, since a
     * rewrite changes what their sites send.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can change the rewrite or switch it on or off.
     */
    public function update(User $user, ResponseRewrite $rewrite): bool
    {
        return $user->hasTeamPermission($rewrite->team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can delete the rewrite.
     */
    public function delete(User $user, ResponseRewrite $rewrite): bool
    {
        return $user->hasTeamPermission($rewrite->team, TeamPermission::ManageDomains);
    }
}
