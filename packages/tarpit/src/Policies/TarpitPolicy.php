<?php

namespace NiceTry\Tarpit\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use NiceTry\Tarpit\Models\Tarpit;

class TarpitPolicy
{
    /**
     * Determine whether the user can see the tarpit and what it held.
     */
    public function view(User $user, Tarpit $tarpit): bool
    {
        return $user->belongsToTeam($tarpit->team);
    }

    /**
     * Determine whether the user can set up tarpits for the team: whoever manages its gym, since both
     * decide what happens to the clients rules catch.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGym);
    }

    /**
     * Determine whether the user can change the tarpit, switch it on or off, and send rules' clients to it.
     */
    public function update(User $user, Tarpit $tarpit): bool
    {
        return $user->hasTeamPermission($tarpit->team, TeamPermission::ManageGym);
    }

    /**
     * Determine whether the user can delete the tarpit.
     */
    public function delete(User $user, Tarpit $tarpit): bool
    {
        return $user->hasTeamPermission($tarpit->team, TeamPermission::ManageGym);
    }
}
