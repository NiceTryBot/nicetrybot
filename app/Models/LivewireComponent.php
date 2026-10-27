<?php

namespace App\Models;

use App\Support\LivewireComponentSources;
use Database\Factories\LivewireComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A Livewire v3 component, by name (e.g. app.filament.auth.login). Livewire v3 posts every component to
 * /livewire/update, so the edge reads the name from the request body, only on routes an entry in its
 * capture feed (an add-on's capture or tag, like a monitor's) names a component for, and each request
 * it named is linked here through livewire_calls.
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class LivewireComponent extends Model
{
    /** @use HasFactory<LivewireComponentFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Limit to the components seen called on one of a team's domains, or that an add-on names for it
     * (App\Support\LivewireComponentSource), like a capture's or a monitor's.
     *
     * @param  Builder<self>  $query
     */
    public function scopeKnownTo(Builder $query, Team $team): void
    {
        $query->where(function (Builder $query) use ($team): void {
            $query
                ->whereIn('id', DB::table('livewire_calls as lc')
                    ->join('edge_requests as r', 'r.id', '=', 'lc.edge_request_id')
                    ->whereIn('r.domain_id', $team->domains()->select('id'))
                    ->select('lc.livewire_component_id'));

            foreach (app(LivewireComponentSources::class)->all() as $source) {
                $query->orWhereIn('id', $source->componentIds($team));
            }
        });
    }
}
