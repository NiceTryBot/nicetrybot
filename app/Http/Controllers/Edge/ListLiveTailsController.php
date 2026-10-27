<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\LiveTail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ListLiveTailsController extends Controller
{
    /**
     * The cache key holding when the edge last asked, so Activity can tell a live tail is connected.
     */
    public const string POLLED_AT_CACHE_KEY = 'edge.live-tails.polled-at';

    /**
     * Tell the edge which hostnames someone is watching live, so it sends just their requests to the
     * ingest endpoint as they happen. Usually the list is empty and the edge sends nothing. The edge
     * polls this and keeps its last good answer when the app can't be reached. A domain that serves www.
     * is watched under www. too.
     */
    public function __invoke(): JsonResponse
    {
        Cache::put(self::POLLED_AT_CACHE_KEY, now()->getTimestamp(), now()->addMinutes(5));

        $tails = LiveTail::query()->active()->get(['team_id', 'domain_id']);

        if ($tails->isEmpty()) {
            return response()->json(['hosts' => []]);
        }

        [$oneDomain, $wholeTeam] = $tails->partition(fn (LiveTail $tail) => $tail->domain_id !== null);

        return response()->json([
            'hosts' => Domain::query()
                ->where(fn (Builder $query) => $query
                    ->whereIn('id', $oneDomain->pluck('domain_id'))
                    ->orWhereIn('team_id', $wholeTeam->pluck('team_id')))
                ->withEdgeHostnames()
                ->get(['hostname', 'preview_hostname', 'origin_id', 'serves_www'])
                ->flatMap(fn (Domain $domain) => $domain->edgeHostnames())
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ]);
    }
}
