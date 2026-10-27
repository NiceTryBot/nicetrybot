<?php

namespace Tests\Fixtures\Addons\Example\Edge;

use App\Support\CaptureFeedHosts;
use App\Support\LockdownFeedContributor;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The example add-on's part of the edge's lockdown feed: a bookmark of /locked holds its domain's
 * hostnames behind a page that names the edge that asked.
 */
class BookmarkLockdowns implements LockdownFeedContributor
{
    public function lockdowns(string $edge): array
    {
        $bookmarks = Bookmark::query()->where('path', '/locked')->orderBy('id')->get();
        $hosts = new CaptureFeedHosts;

        $hosts->load($bookmarks->pluck('team_id')->unique());

        return $bookmarks
            ->map(fn (Bookmark $bookmark) => [
                'team' => $bookmark->team_id,
                'hosts' => $hosts->for($bookmark->team_id, $bookmark->domain_id),
                'allow' => [],
                'secret' => 'example-secret',
                'html' => 'Held for '.$edge,
                'retry_after' => 60,
            ])
            ->all();
    }
}
