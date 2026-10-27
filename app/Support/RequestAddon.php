<?php

namespace App\Support;

use App\Models\EdgeRequest;
use App\Models\Team;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Something a package shows about requests on the Activity page, from outside the core: a mark under
 * each request's path in the list, a section in one request's details, and buttons beside the core's
 * there. Add-ons are tagged in the container under RequestAddons::TAG.
 */
interface RequestAddon
{
    /**
     * Draw the add-on's mark for a page of requests at once, so it can load what it needs in one go.
     *
     * @param  array<int, int>  $requestIds
     * @return array<int, Htmlable|null> Keyed like the ids given.
     */
    public function marks(Team $team, array $requestIds): array;

    /**
     * Draw what the add-on knows about one request, under what the edge recorded about it.
     */
    public function details(Team $team, EdgeRequest $request): ?Htmlable;

    /**
     * Draw the add-on's buttons for one request, before the core's. A button that runs an action is a
     * Livewire component of the add-on's own.
     */
    public function actions(Team $team, EdgeRequest $request): ?Htmlable;
}
