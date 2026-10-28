<?php

namespace App\Http\Controllers\Edge;

use App\Data\EdgeRuleEnvironment;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Support\TarpitFeed;
use App\Support\ViewerIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListTarpitsController extends Controller
{
    /**
     * Tell the edge who to make wait, and how. Add-ons fill the list (App\Support\TarpitFeedContributor),
     * told which edge asked; with none it's empty and nobody waits. Alongside comes what the tarpits'
     * single-request rules need (EdgeRuleEnvironment), for the domains in Enforce mode. The edge polls
     * this every few seconds with an ETag, so an unchanged answer costs a 304, and keeps its last good
     * answer when the app can't be reached.
     */
    public function __invoke(Request $request, TarpitFeed $feed): JsonResponse
    {
        $tarpits = $feed->tarpits(ViewerIp::of($request) ?? 'edge');

        if ($tarpits === []) {
            return response()->json(['tarpits' => []]);
        }

        $domains = Domain::query()
            ->where('protection_mode', ProtectionMode::Enforce)
            ->where('status', DomainStatus::Active)
            ->orderBy('hostname')
            ->withEdgeHostnames()
            ->get(['team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www']);

        return response()->json([
            'tarpits' => $tarpits,
            ...EdgeRuleEnvironment::for($domains),
        ]);
    }
}
