<?php

namespace App\Actions\Gym;

use App\Data\GymVisitor;
use App\Enums\TlsFingerprintType;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MatchGymRoutines
{
    /**
     * Find every enabled routine a visitor enters, in stacking order. The visitor's active verdicts are
     * looked up first, so "has a verdict from" conditions see them.
     *
     * @return Collection<int, GymRoutine>
     */
    public function handle(Team $team, GymVisitor $visitor): Collection
    {
        $visitor = $visitor->withVerdicts($this->verdictRuleIds($team, $visitor));

        return $team->gymRoutines()
            ->where('enabled', true)
            ->get()
            ->filter(fn (GymRoutine $routine) => $routine->entry()->admits($visitor))
            ->values();
    }

    /**
     * Get the rules holding an active verdict on the visitor: one on their IP, their TLS fingerprint,
     * or both together.
     *
     * @return list<int>
     */
    public function verdictRuleIds(Team $team, GymVisitor $visitor): array
    {
        return array_values($this->activeVerdicts($team, $visitor)
            ->whereNotNull('edge_rule_id')
            ->distinct()
            ->pluck('edge_rule_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Start a query for the team's verdicts in force on the visitor: on their IP, their TLS
     * fingerprint, or both together.
     *
     * @return Builder<Verdict>
     */
    public function activeVerdicts(Team $team, GymVisitor $visitor): Builder
    {
        $ja4Ids = $visitor->ja4s() === [] ? [] : TlsFingerprint::query()
            ->where('type', TlsFingerprintType::Ja4)
            ->whereIn('value', $visitor->ja4s())
            ->pluck('id')
            ->all();

        return Verdict::query()
            ->active()
            ->where('team_id', $team->id)
            ->where(fn (Builder $query) => $query->whereNull('client_ip')->orWhere('client_ip', $visitor->ip))
            ->where(fn (Builder $query) => $ja4Ids === [] ? $query->whereNull('ja4_id') : $query->whereNull('ja4_id')->orWhereIn('ja4_id', $ja4Ids));
    }
}
