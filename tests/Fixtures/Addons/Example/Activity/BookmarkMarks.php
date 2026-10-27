<?php

namespace Tests\Fixtures\Addons\Example\Activity;

use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\RequestAddon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The example add-on on the Activity page: "bookmarked" under each request to a bookmarked page, the
 * bookmark in its details, and a button.
 */
class BookmarkMarks implements RequestAddon
{
    public function marks(Team $team, array $requestIds): array
    {
        $bookmarked = array_flip(EdgeRequest::query()
            ->join('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->whereIn('edge_requests.id', array_values($requestIds))
            ->whereIn('request_paths.path', Bookmark::query()->where('team_id', $team->id)->select('path'))
            ->pluck('edge_requests.id')
            ->all());

        return array_map(fn (int $id) => isset($bookmarked[$id]) ? new HtmlString('<span data-test="example-bookmarked">bookmarked</span>') : null, $requestIds);
    }

    public function details(Team $team, EdgeRequest $request): ?Htmlable
    {
        return new HtmlString('<p data-test="example-details">Bookmark details for '.e($request->path).'</p>');
    }

    public function actions(Team $team, EdgeRequest $request): ?Htmlable
    {
        return new HtmlString('<button type="button" data-test="example-action">Bookmark it</button>');
    }
}
