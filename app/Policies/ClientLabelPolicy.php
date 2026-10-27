<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\ClientLabel;
use App\Models\Team;
use App\Models\User;

class ClientLabelPolicy
{
    /**
     * Determine whether the user can label values for the team. Labels feed hunting alongside rules,
     * so the same permission governs both.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageRules);
    }

    /**
     * Determine whether the user can rename or remove the label.
     */
    public function update(User $user, ClientLabel $clientLabel): bool
    {
        return $user->hasTeamPermission($clientLabel->team, TeamPermission::ManageRules);
    }
}
