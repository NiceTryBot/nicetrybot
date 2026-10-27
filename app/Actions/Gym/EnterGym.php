<?php

namespace App\Actions\Gym;

use App\Data\GymVisitor;
use App\Enums\GymSessionStatus;
use App\Models\Domain;
use App\Models\GymSession;
use App\Models\Team;
use Illuminate\Support\Str;

class EnterGym
{
    public function __construct(private MatchGymRoutines $matchGymRoutines)
    {
        //
    }

    /**
     * Start a gym session for a visitor who matches at least one of the team's routines, remembering
     * where they were going. Returns null when no routine wants them, so they go straight through.
     *
     * This is the hand-off point for the edge: once Caddy diverts matching visitors, it lands here.
     */
    public function handle(Team $team, GymVisitor $visitor, ?string $destination = null, ?Domain $domain = null): ?GymSession
    {
        $routines = $this->matchGymRoutines->handle($team, $visitor);

        if ($routines->isEmpty()) {
            return null;
        }

        $session = $team->gymSessions()->create([
            'domain_id' => $domain?->id,
            'token' => Str::random(40),
            'is_preview' => false,
            'status' => GymSessionStatus::Training,
            'destination' => $destination,
            'client_ip' => $visitor->ip,
            'user_agent' => $visitor->userAgent === null ? null : mb_substr($visitor->userAgent, 0, 512),
            'ja4' => $visitor->ja4,
            'country_code' => $visitor->countryCode,
            'asn' => $visitor->asn,
            'progress' => [],
            'escalations' => [],
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        $session->routines()->attach($routines->modelKeys());

        return $session;
    }
}
