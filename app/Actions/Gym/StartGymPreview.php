<?php

namespace App\Actions\Gym;

use App\Enums\GymSessionStatus;
use App\Models\GymSession;
use App\Models\Team;
use Illuminate\Support\Str;

class StartGymPreview
{
    /**
     * Start a preview session that walks a routine draft, saved or not, exactly as a visitor would.
     * Previews stay out of the gym's numbers.
     *
     * @param  list<array<string, mixed>>  $blueprints  Routine blueprints, stacked in order.
     */
    public function handle(Team $team, array $blueprints): GymSession
    {
        return $team->gymSessions()->create([
            'token' => Str::random(40),
            'is_preview' => true,
            'status' => GymSessionStatus::Training,
            'progress' => [],
            'escalations' => [],
            'draft' => $blueprints,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
