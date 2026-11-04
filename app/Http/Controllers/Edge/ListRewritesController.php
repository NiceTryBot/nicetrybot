<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\RewriteFeed;
use Illuminate\Http\JsonResponse;

class ListRewritesController extends Controller
{
    /**
     * Tell the edge what to change in origins' responses, and on which hostnames. Add-ons fill the list
     * (App\Support\RewriteFeedContributor); with none it's empty and responses pass untouched. The edge
     * polls this every few seconds with an ETag, so an unchanged answer costs a 304, and keeps its last
     * good answer when the app can't be reached.
     */
    public function __invoke(RewriteFeed $feed): JsonResponse
    {
        return response()->json([
            'rewrites' => $feed->rewrites(),
        ]);
    }
}
