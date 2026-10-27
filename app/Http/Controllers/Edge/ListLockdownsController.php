<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\LockdownFeed;
use App\Support\ViewerIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListLockdownsController extends Controller
{
    /**
     * Tell the edge which hostnames are locked down and what to serve on them. Add-ons fill the list
     * (App\Support\LockdownFeedContributor), told which edge asked; with none it's empty, which unlocks
     * everything. The edge polls this every few seconds (with an ETag, so an unchanged answer costs a
     * 304), keeps its last good answer when the app can't be reached, and saves it to disk so a restart
     * keeps sites locked.
     */
    public function __invoke(Request $request, LockdownFeed $feed): JsonResponse
    {
        return response()->json([
            'lockdowns' => $feed->lockdowns(ViewerIp::of($request) ?? 'edge'),
        ]);
    }
}
