<?php

namespace NiceTry\Lockdown\Edge;

use App\Support\LockdownFeedContributor;
use NiceTry\Lockdown\Models\Lockdown;

/**
 * The engaged lockdowns, in the edge's lockdown feed: every hostname of each one's locked sites, its
 * allowlist, the secret that signs its passes and its maintenance page. Each time an edge asks, its
 * check-in is noted, so the Lockdown page can show when a change has reached it.
 */
class EngagedLockdowns implements LockdownFeedContributor
{
    public function lockdowns(string $edge): array
    {
        Lockdown::recordEdgeCheckIn($edge);

        return Lockdown::query()
            ->whereNotNull('engaged_at')
            ->with('team')
            ->orderBy('id')
            ->get()
            ->map(fn (Lockdown $lockdown) => $lockdown->edgePayload())
            ->all();
    }
}
