<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\HoneypotFeed;
use Illuminate\Http\JsonResponse;

class ListHoneypotsController extends Controller
{
    /**
     * Tell the edge which paths' 404s go to a honeypot, and on which hostnames. Add-ons fill the list
     * (App\Support\HoneypotFeedContributor); with none it's empty and every 404 stays the site's. The edge
     * polls this every few seconds with an ETag, so an unchanged answer costs a 304, and keeps its last
     * good answer when the app can't be reached.
     */
    public function __invoke(HoneypotFeed $feed): JsonResponse
    {
        return response()->json([
            'honeypots' => $feed->honeypots(),
        ]);
    }
}
