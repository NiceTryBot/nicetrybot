<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\AllowedAddresses;
use Illuminate\Http\JsonResponse;

class ListAllowedAddressesController extends Controller
{
    /**
     * Tell the edge the IP addresses and CIDR ranges to let past its own enforcements — the tarpit's
     * burst and not-found counters and its rule matches, the gym, the standing verdicts — before any of
     * them hold a request. Add-ons fill the list (App\Support\AllowedAddressSource); with none on it's
     * empty, which lets the edge's enforcements run as before. The edge polls this every few seconds
     * (with an ETag, so an unchanged answer costs a 304) and keeps its last good answer when the app
     * can't be reached, so a blip never starts tarpitting an allowed crawler.
     */
    public function __invoke(AllowedAddresses $allowed): JsonResponse
    {
        return response()->json([
            'allow' => $allowed->ranges(),
        ]);
    }
}
