<?php

namespace NiceTry\PrivacyMode\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NiceTry\PrivacyMode\Support\HostnameMap;

/**
 * Serves the current team's real hostname → stand-in map for privacy mode as a script, versioned in
 * its URL so the browser keeps it instead of every page carrying it (thousands of domains add up).
 */
class HostnamesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $script = 'window.ntbPrivacyHostnames='.json_encode(HostnameMap::encodedMapFor($team)).';'
            ."window.dispatchEvent(new Event('privacy-hostnames-loaded'));";

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $request->query('v') === HostnameMap::versionFor($team) ? 'private, max-age=31536000, immutable' : 'private, no-cache',
        ]);
    }
}
