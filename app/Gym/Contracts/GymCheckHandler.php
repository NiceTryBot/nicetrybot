<?php

namespace App\Gym\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Something a package answers the edge's gym check with before the core's routines are looked at: a
 * page of its own for a visitor carrying its cookie, say. Handlers are tagged in the container under
 * App\Gym\GymCheckHandlers::TAG and asked in that order; the first answer wins.
 */
interface GymCheckHandler
{
    /**
     * Answer the gym check for this request, or return null to leave it to the next handler and then
     * the core. The request carries what the edge describes about the visitor (X-NiceTry-* headers) and
     * only the gym's own cookies.
     */
    public function handle(Request $request): ?Response;
}
