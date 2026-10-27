<?php

namespace App\Support;

use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Something outside the core that names Livewire components for a team, like an add-on's capture or
 * monitor on one. Sources are tagged in the container under LivewireComponentSources::TAG, and the components
 * they name are suggested wherever a team names one.
 */
interface LivewireComponentSource
{
    /**
     * A query selecting the ids of the components the team names, in one column, to use as a subquery.
     */
    public function componentIds(Team $team): Builder;
}
