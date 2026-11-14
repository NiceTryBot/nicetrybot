<?php

namespace NiceTry\SwarmMode\Models;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Swarm Mode turned on by hand, for a site or one path on it, until ends_at (or ended_at, when someone
 * ended it early). It covers a request just as a swarm rule's verdict does, under the tag TAG.
 *
 * @property int $id
 * @property int $team_id
 * @property int $domain_id
 * @property int|null $path_id
 * @property int|null $created_by_id
 * @property Carbon $started_at
 * @property Carbon $ends_at
 * @property Carbon|null $ended_at
 * @property-read Domain $domain
 * @property-read User|null $createdBy
 */
#[Fillable(['team_id', 'domain_id', 'path_id', 'created_by_id', 'started_at', 'ends_at', 'ended_at'])]
class ManualSwarm extends Model
{
    /**
     * The tag a request under a hand-set Swarm Mode reads as in the `swarm` field.
     */
    public const string TAG = 'swarm-manual';

    public $timestamps = false;

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Limit to those on right now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('started_at', '<=', now())->where('ends_at', '>', now());
    }

    /**
     * Limit to those covering a request to this site and path: the whole site, or that path on it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCovering(Builder $query, int $domainId, ?int $pathId): void
    {
        $query->where('domain_id', $domainId)
            ->where(fn (Builder $query) => $pathId === null ? $query->whereNull('path_id') : $query->whereNull('path_id')->orWhere('path_id', $pathId));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'path_id' => 'integer',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
