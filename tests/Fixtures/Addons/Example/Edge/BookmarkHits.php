<?php

namespace Tests\Fixtures\Addons\Example\Edge;

use App\Models\Domain;
use App\Support\EdgeLineAddon;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The example add-on's part of the log import: a line's "bookmark" (a path) is kept as a bookmark beside
 * the request, on the request's team, unless it doesn't start with a slash.
 */
class BookmarkHits implements EdgeLineAddon
{
    public function read(array $line): ?string
    {
        return is_string($line['bookmark'] ?? null) ? $line['bookmark'] : null;
    }

    public function keep(array $rows): array
    {
        $teamIds = Domain::query()->whereIn('id', array_column($rows, 'domain_id'))->pluck('team_id', 'id');

        return array_map(
            fn (array $row) => ['team_id' => $teamIds[$row['domain_id']], 'domain_id' => $row['domain_id'], 'path' => $row['read']],
            array_filter($rows, fn (array $row) => str_starts_with($row['read'], '/')),
        );
    }

    public function store(array $kept): void
    {
        foreach ($kept as $edgeRequestId => $bookmark) {
            Bookmark::create([...$bookmark, 'edge_request_id' => $edgeRequestId]);
        }
    }
}
