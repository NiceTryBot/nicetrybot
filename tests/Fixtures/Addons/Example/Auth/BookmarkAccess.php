<?php

namespace Tests\Fixtures\Addons\Example\Auth;

use App\Models\User;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The bookmark's policy, named so the Gate can't guess it: only the add-on's $policies find it.
 */
class BookmarkAccess
{
    /**
     * Determine whether the user can see the bookmark: anyone on its team.
     */
    public function view(User $user, Bookmark $bookmark): bool
    {
        return $user->current_team_id === $bookmark->team_id;
    }
}
