<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\SavedSearch;
use App\Models\Team;
use App\Models\User;

class SavedSearchPolicy
{
    /**
     * Determine whether the user can save a search for the team. Anyone who can search the team's
     * activity can save what they found.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can rename or re-save the search: whoever saved it, or anyone who
     * manages the team's rules.
     */
    public function update(User $user, SavedSearch $savedSearch): bool
    {
        return $savedSearch->user_id === $user->id || $user->hasTeamPermission($savedSearch->team, TeamPermission::ManageRules);
    }

    /**
     * Determine whether the user can remove the search.
     */
    public function delete(User $user, SavedSearch $savedSearch): bool
    {
        return $this->update($user, $savedSearch);
    }
}
