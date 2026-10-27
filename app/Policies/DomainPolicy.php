<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;

class DomainPolicy
{
    /**
     * Determine whether the user can view the domain.
     */
    public function view(User $user, Domain $domain): bool
    {
        return $user->belongsToTeam($domain->team);
    }

    /**
     * Determine whether the user can add domains to the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can update the domain.
     */
    public function update(User $user, Domain $domain): bool
    {
        return $user->hasTeamPermission($domain->team, TeamPermission::ManageDomains);
    }

    /**
     * Determine whether the user can delete the domain.
     */
    public function delete(User $user, Domain $domain): bool
    {
        return $user->hasTeamPermission($domain->team, TeamPermission::ManageDomains);
    }
}
