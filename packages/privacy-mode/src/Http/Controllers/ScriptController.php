<?php

namespace NiceTry\PrivacyMode\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NiceTry\PrivacyMode\Support\Script;

/**
 * Serves privacy mode's script to every page with the app's head, signed in or not, as it was when it
 * came in the core's bundle. Kept for good at its current version; anything else is checked each time.
 */
class ScriptController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response(Script::source(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $request->query('v') === Script::version() ? 'public, max-age=31536000, immutable' : 'public, no-cache',
        ]);
    }
}
