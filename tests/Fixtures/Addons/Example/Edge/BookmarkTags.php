<?php

namespace Tests\Fixtures\Addons\Example\Edge;

use App\Support\CaptureFeedContributor;
use App\Support\CaptureFeedHosts;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The example add-on's part of the edge's capture feed: each bookmark as a tag.
 */
class BookmarkTags implements CaptureFeedContributor
{
    public function contribute(CaptureFeedHosts $hosts): array
    {
        $bookmarks = Bookmark::query()->orderBy('id')->get();

        $hosts->load($bookmarks->pluck('team_id')->unique());

        return [
            'tags' => $bookmarks
                ->map(fn (Bookmark $bookmark) => ['id' => $bookmark->id, 'hosts' => $hosts->for($bookmark->team_id, $bookmark->domain_id), 'method' => null, 'path' => $bookmark->path, 'component' => null])
                ->all(),
        ];
    }
}
