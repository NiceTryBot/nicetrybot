<?php

namespace App\Support;

use App\Models\EdgeRequest;
use App\Models\Team;

/**
 * A request add-on (App\Support\RequestAddon) that also recorded what the visitor sent, so the request can
 * be copied as a curl command with its body.
 */
interface RequestBodyAddon
{
    /**
     * Get what the visitor sent, redacted on the edge, or null when the add-on didn't record it.
     *
     * @return array{content_type: string|null, body: string}|null
     */
    public function sentBody(Team $team, EdgeRequest $request): ?array;
}
