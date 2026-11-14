<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rule's decision about a scope rather than a client, from a rule over host and path: a site (only
 * domain_id), a path on every one of the team's sites (only path_id), or one path on one site (both),
 * e.g. "in Swarm Mode for 5 minutes". It covers everyone on that site or path while it lasts.
 *
 * @property int $id
 * @property int $team_id
 * @property int $edge_rule_id
 * @property string|null $tag
 * @property int|null $domain_id
 * @property int|null $path_id
 * @property int $matched_requests
 * @property Carbon $first_matched_at
 * @property Carbon $last_matched_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $lifted_at
 * @property Carbon|null $created_at
 * @property-read EdgeRule $rule
 * @property-read Domain|null $domain
 */
#[Fillable(['team_id', 'edge_rule_id', 'tag', 'domain_id', 'path_id', 'matched_requests', 'first_matched_at', 'last_matched_at', 'expires_at', 'lifted_at'])]
class ScopeVerdict extends Model
{
    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the rule that reached the verdict.
     *
     * @return BelongsTo<EdgeRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EdgeRule::class, 'edge_rule_id');
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Limit to verdicts still in force: not lifted and not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Limit to verdicts that cover a request to this site and path: one on the site, one on the path on
     * every site, or one on that path on that site.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCovering(Builder $query, int $domainId, ?int $pathId): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('domain_id')->orWhere('domain_id', $domainId))
            ->where(fn (Builder $query) => $pathId === null ? $query->whereNull('path_id') : $query->whereNull('path_id')->orWhere('path_id', $pathId))
            ->where(fn (Builder $query) => $query->whereNotNull('domain_id')->orWhereNotNull('path_id'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'path_id' => 'integer',
            'matched_requests' => 'integer',
            'first_matched_at' => 'datetime',
            'last_matched_at' => 'datetime',
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }
}
