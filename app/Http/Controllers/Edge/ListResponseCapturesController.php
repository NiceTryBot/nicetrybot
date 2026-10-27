<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\CaptureFeed;
use Illuminate\Http\JsonResponse;

class ListResponseCapturesController extends Controller
{
    /**
     * Tell the edge's capture module which routes to watch: under captures, the routes to record
     * responses on (whether to read what visitors send there too, and which of the team's watchwords
     * to look for in it), and under tags, routes the edge records nothing on, only naming the Livewire
     * component each request calls. Add-ons fill both lists (App\Support\CaptureFeedContributor); with
     * none, both are empty. The edge polls this (with an ETag, so an unchanged list costs a 304) and
     * keeps its last good answer when the app can't be reached, so a capture starts within one poll and
     * stops the same way.
     */
    public function __invoke(CaptureFeed $feed): JsonResponse
    {
        return response()->json($feed->payload());
    }
}
