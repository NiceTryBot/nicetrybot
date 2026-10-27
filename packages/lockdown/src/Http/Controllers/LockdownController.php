<?php

namespace NiceTry\Lockdown\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use NiceTry\Lockdown\Models\Lockdown;

class LockdownController extends Controller
{
    /**
     * How long a pass through the lockdown lasts in the browser that took it.
     */
    private const int PASS_HOURS = 12;

    /**
     * Send an admin to one of a site's hostnames with a pass through the lockdown: the edge checks the
     * link, sets the pass cookie on that hostname and shows the real site.
     */
    public function pass(Request $request, Team $currentTeam, Domain $domain): RedirectResponse
    {
        $lockdown = Lockdown::for($currentTeam);

        Gate::authorize('update', $lockdown);
        abort_unless($domain->team_id === $currentTeam->id, 404);

        $hostname = in_array($request->query('host'), $domain->edgeHostnames(), true)
            ? $request->query('host')
            : $domain->hostname;

        return redirect()->away($lockdown->passUrl($hostname, now()->addHours(self::PASS_HOURS)));
    }

    /**
     * Show the team's maintenance page exactly as the edge serves it.
     */
    public function preview(Team $currentTeam): Response
    {
        $lockdown = Lockdown::for($currentTeam);

        Gate::authorize('view', $lockdown);

        return response($lockdown->page())->header('Content-Type', 'text/html; charset=utf-8');
    }
}
