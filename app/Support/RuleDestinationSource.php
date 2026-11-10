<?php

namespace App\Support;

use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * Something rules can send clients to, for the Rules page's board.
 */
interface RuleDestinationSource
{
    /**
     * The columns this source brings to the board.
     *
     * @return list<RuleBoardColumn>
     */
    public function columns(): array;

    /**
     * Where each of a team's rules sends its clients, keyed by rule id. A rule it doesn't reach is left out.
     *
     * @param  Collection<int, EdgeRule>  $rules
     * @return array<int, list<RuleDestination>>
     */
    public function destinations(Team $team, Collection $rules): array;
}
