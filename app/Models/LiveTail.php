<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LiveTailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone watching Activity live: while it's active, the edge sends that team's requests (or one
 * domain's) to the app every couple of seconds instead of waiting for the minute import. It ends after
 * LIFETIME_MINUTES, when it's turned off or its user logs out, and pauses once the page stops checking in.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $domain_id Only this domain's requests; null tails all of the team's domains.
 * @property Carbon $expires_at
 * @property Carbon $last_seen_at When the page last checked in.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 * @property-read Domain|null $domain
 */
#[Fillable(['user_id', 'domain_id', 'expires_at', 'last_seen_at'])]
class LiveTail extends Model
{
    /** @use HasFactory<LiveTailFactory> */
    use HasFactory;

    /**
     * How long a live tail lasts once turned on.
     */
    public const int LIFETIME_MINUTES = 10;

    /**
     * How long the page can go without checking in (closed, or in a background tab) before the edge
     * stops sending its requests.
     */
    public const int IDLE_SECONDS = 30;

    /**
     * Get the team whose requests are tailed.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user watching.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the one domain tailed, if the page is filtered to it.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get when the tail was turned on (it's turned on for exactly LIFETIME_MINUTES).
     */
    public function startedAt(): CarbonInterface
    {
        return $this->expires_at->subMinutes(self::LIFETIME_MINUTES);
    }

    /**
     * Determine if the tail hasn't run out yet (it may still be idle).
     */
    public function isRunning(): bool
    {
        return $this->expires_at->isFuture();
    }

    /**
     * Limit to tails the edge should send requests for: not expired, and the page checked in recently.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subSeconds(self::IDLE_SECONDS));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
