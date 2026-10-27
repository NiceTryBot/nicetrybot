<?php

namespace App\Http\Controllers\Edge;

use App\Enums\DomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

class ListOriginsController extends Controller
{
    /**
     * Tell the edge where each verified domain's traffic goes and how to dress it for the origin. The
     * edge polls this (with an ETag, so an unchanged list costs a 304) and keeps its last good answer
     * when the app can't be reached. Hostnames missing here get the edge's 503. A domain that serves www.
     * (Domain::servesWww()) is listed under www. too, with the same route. A www.www. site never is, nor
     * a site whose shared origin turns its name away (Domain::refusesHostname()).
     */
    public function __invoke(): JsonResponse
    {
        $domains = Domain::query()
            ->withEdgeHostnames()
            ->whereIn('status', [DomainStatus::Active, DomainStatus::Paused])
            ->whereNotNull('verified_at')
            ->whereNot('hostname', 'like', 'www.www.%')
            ->orderBy('hostname')
            ->get()
            ->reject(fn (Domain $domain) => $domain->refusesHostname($domain->hostname));

        $routes = $domains->mapWithKeys(fn (Domain $domain) => [$domain->hostname => $domain->edgeRoute()]);

        foreach ($domains as $domain) {
            foreach ($domain->edgeHostnames() as $hostname) {
                if (! $routes->has($hostname)) {
                    $routes->put($hostname, $domain->edgeRoute($hostname));
                }
            }
        }

        return response()->json([
            'origins' => $routes->sortKeys()->values()->all(),
        ]);
    }
}
