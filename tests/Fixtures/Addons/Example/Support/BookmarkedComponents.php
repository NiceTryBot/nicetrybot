<?php

namespace Tests\Fixtures\Addons\Example\Support;

use App\Models\Team;
use App\Support\LivewireComponentSource;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

/**
 * The Livewire components a team's bookmarks are on.
 */
class BookmarkedComponents implements LivewireComponentSource
{
    public function componentIds(Team $team): Builder
    {
        return Bookmark::query()->where('team_id', $team->id)->whereNotNull('livewire_component_id')->select('livewire_component_id');
    }
}
