<?php

namespace App\Http\Controllers\Edge;

use App\Enums\DomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

class ListSiteHeadersController extends Controller
{
    /**
     * Tell the edge which domains answer with their own Server header instead of its default. The edge
     * polls this and keeps its last good answer when the app can't be reached. A domain that serves www.
     * is listed under www. too.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'sites' => Domain::query()
                ->whereIn('status', [DomainStatus::Active, DomainStatus::Paused])
                ->whereNotNull('server_header')
                ->withEdgeHostnames()
                ->get(['hostname', 'preview_hostname', 'server_header', 'origin_id', 'serves_www'])
                ->flatMap(fn (Domain $domain) => array_map(
                    fn (string $hostname) => ['host' => $hostname, 'server' => $domain->server_header],
                    $domain->edgeHostnames(),
                ))
                ->unique('host')
                ->sortBy('host')
                ->values()
                ->all(),
        ]);
    }
}
