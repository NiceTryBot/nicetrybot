<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Domains\ResolveHostname;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResolveOriginController extends Controller
{
    /**
     * Tell the edge where a hostname it hasn't heard of yet should go. The edge asks when a request
     * arrives for a host missing from its last poll, typically a site an origin vouched for moments ago,
     * or www.site served as site.
     */
    public function __invoke(Request $request, ResolveHostname $resolveHostname): JsonResponse
    {
        $hostname = Str::lower((string) $request->query('host'));
        $domain = $resolveHostname->handle($hostname);

        abort_unless($domain, 404);

        return response()->json($domain->edgeRoute(in_array($hostname, $domain->edgeHostnames(), true) ? $hostname : null));
    }
}
