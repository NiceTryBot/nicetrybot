<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Gym\MatchGymRoutines;
use App\Data\GymVisitor;
use App\Data\LiveRequest;
use App\Enums\DomainStatus;
use App\Enums\GymPassState;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class GymReportController extends Controller
{
    /**
     * Settle a request the gym check let through provisionally, now the edge knows its status: when
     * it satisfies the UNTIL of a rule holding a verdict on the visitor (e.g. the dashboard answered
     * 200 after signing in), that verdict is lifted on the spot instead of at the next rule run, and
     * the visitor's pass is fresh again. Otherwise the pass goes back to being spent.
     */
    public function __invoke(Request $request, MatchGymRoutines $matchGymRoutines): Response
    {
        $domain = Domain::query()
            ->servedAs(Str::lower((string) $request->header('X-NiceTry-Host')))
            ->where('status', DomainStatus::Active)
            ->first();

        if ($domain === null) {
            return response()->noContent();
        }

        $pass = $request->cookie(GymSession::PASS_COOKIE);
        $session = is_string($pass) ? GymSession::fromPass($pass, $domain->team_id) : null;

        $ip = (string) $request->header('X-NiceTry-Client-Ip');
        $path = (string) parse_url('/'.ltrim((string) $request->header('X-NiceTry-Uri', '/'), '/'), PHP_URL_PATH);
        // Known by the fingerprint it entered the gym with, as the gym check does.
        $visitor = new GymVisitor(ip: filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0', host: $domain->hostname, path: $path, userAgent: $request->userAgent(), ja4: $session?->ja4 ?? ($request->header('X-NiceTry-JA4') ?: null));
        $live = new LiveRequest(
            method: strtoupper((string) $request->header('X-NiceTry-Method', 'GET')),
            host: $domain->hostname,
            path: $path,
            status: (int) $request->header('X-NiceTry-Status') ?: null,
            ip: $visitor->ip,
            ja4: $visitor->ja4,
            userAgent: $visitor->userAgent,
        );

        $liftingRuleIds = EdgeRule::query()
            ->whereIn('id', $matchGymRoutines->verdictRuleIds($domain->team, $visitor))
            ->get()
            ->filter(fn (EdgeRule $rule) => ($until = rescue(fn () => $rule->script()->until, report: false)) !== null && $live->evaluate($until) === true)
            ->modelKeys();

        if ($liftingRuleIds !== []) {
            $matchGymRoutines->activeVerdicts($domain->team, $visitor)->whereIn('edge_rule_id', $liftingRuleIds)->update(['lifted_at' => now()]);
        }

        if ($session?->pass_state === GymPassState::Provisional) {
            $session->update(['pass_state' => $liftingRuleIds === [] ? GymPassState::Spent : null]);
        }

        return response()->noContent();
    }
}
