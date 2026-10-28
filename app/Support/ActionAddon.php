<?php

namespace App\Support;

use App\Models\Team;

/**
 * Something a package says about what happened to requests, in Activity's Action column, from outside
 * the core: a tarpit that held a request, say. Add-ons are tagged in the container under
 * ActionAddons::TAG.
 */
interface ActionAddon
{
    /**
     * The add-on's badges for a page of requests at once, so it can load what it needs in one go.
     *
     * @param  array<int, int>  $requestIds
     * @return array<int, ActionBadge|null> Keyed like the ids given.
     */
    public function badges(Team $team, array $requestIds): array;
}
