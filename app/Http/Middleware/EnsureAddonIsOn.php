<?php

namespace App\Http\Middleware;

use App\Addons\AddonSwitchboard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer 404 for an add-on's routes while an administrator has it turned off, as though it weren't
 * installed. Livewire runs it again for updates to the add-on's pages (a persistent middleware), so a
 * page left open stops working too.
 */
class EnsureAddonIsOn
{
    public function __construct(private AddonSwitchboard $switchboard)
    {
        //
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $addon): Response
    {
        abort_unless($this->switchboard->isOn($addon), 404);

        return $next($request);
    }
}
