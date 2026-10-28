<?php

namespace NiceTry\Tarpit\Models;

use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use NiceTry\Tarpit\Database\Factories\TarpitFactory;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitWhenFull;

/**
 * A team's tarpit: clients in it get its free tries, then each request waits at the edge a little
 * longer than the last (its delays in turn, never longer than its cap) before going on, getting a 404
 * or being hung up on. Clients get in through the rules it takes (the clients holding their verdicts,
 * and requests their single-request WHERE matches), as gym no-shows, or by the edge's own count of 404s
 * and bursts. It covers the team's domains in Enforce mode: all of them, or those it names.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property bool $enabled
 * @property bool $all_domains
 * @property int $free_tries
 * @property list<int> $delays Seconds each paid try waits, in turn.
 * @property int $max_delay_seconds
 * @property int $max_held The most requests it holds at once, on each edge.
 * @property TarpitWhenFull $when_full
 * @property TarpitAfterWait $after_wait
 * @property int $forget_after_seconds How long a client must be quiet to start over.
 * @property bool $takes_gym_no_shows
 * @property int|null $not_found_count
 * @property int $not_found_within_seconds
 * @property int|null $burst_count
 * @property int $burst_within_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, EdgeRule> $rules
 * @property-read Collection<int, Domain> $domains
 */
#[Fillable([
    'team_id',
    'name',
    'enabled',
    'all_domains',
    'free_tries',
    'delays',
    'max_delay_seconds',
    'max_held',
    'when_full',
    'after_wait',
    'forget_after_seconds',
    'takes_gym_no_shows',
    'not_found_count',
    'not_found_within_seconds',
    'burst_count',
    'burst_within_seconds',
])]
class Tarpit extends Model
{
    /** @use HasFactory<TarpitFactory> */
    use HasFactory;

    /**
     * The waits a new tarpit starts with, in seconds.
     */
    public const array DEFAULT_DELAYS = [2, 8, 24];

    /**
     * The longest any tarpit may hold a request, in seconds.
     */
    public const int LONGEST_DELAY = 120;

    /**
     * The most requests one tarpit may hold at once on an edge. The edge also caps them across every
     * tarpit (its max_held, 1000 by default).
     */
    public const int MOST_HELD = 1000;

    /**
     * Where the edge's list is kept between polls.
     */
    public const string FEED_CACHE_KEY = 'tarpit:feed';

    protected static function newFactory(): TarpitFactory
    {
        return TarpitFactory::new();
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetFeed());
        static::deleted(fn () => self::forgetFeed());
    }

    /**
     * Drop the edge's cached list, so a change reaches the edge on its next poll.
     */
    public static function forgetFeed(): void
    {
        Cache::forget(self::FEED_CACHE_KEY);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The rules whose clients this tarpit takes.
     *
     * @return BelongsToMany<EdgeRule, $this>
     */
    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(EdgeRule::class, 'tarpit_rules')->withTimestamps();
    }

    /**
     * The domains this tarpit covers, when it doesn't cover them all.
     *
     * @return BelongsToMany<Domain, $this>
     */
    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'tarpit_domains')->withTimestamps();
    }

    /**
     * @return HasMany<TarpitHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(TarpitHold::class);
    }

    /**
     * How long the given try waits, in seconds: nothing for the free tries, then each delay in turn and
     * the cap from then on, never longer than the cap. The edge works it out the same way.
     */
    public function delayFor(int $try): int
    {
        $paid = $try - $this->free_tries;

        if ($paid <= 0) {
            return 0;
        }

        return min($this->delays[$paid - 1] ?? $this->max_delay_seconds, $this->max_delay_seconds);
    }

    /**
     * The waits in order, as a person reads them: "free, free, 2 s, 8 s, 24 s, then 30 s".
     */
    public function describeWaits(): string
    {
        $steps = [
            ...array_fill(0, $this->free_tries, __('free')),
            ...array_map(fn (int $seconds) => __(':s s', ['s' => min($seconds, $this->max_delay_seconds)]), $this->delays),
        ];

        return __(':steps, then :cap s', ['steps' => implode(', ', $steps ?: [__('no free try')]), 'cap' => $this->max_delay_seconds]);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'all_domains' => 'boolean',
            'free_tries' => 'integer',
            'delays' => 'array',
            'max_delay_seconds' => 'integer',
            'max_held' => 'integer',
            'when_full' => TarpitWhenFull::class,
            'after_wait' => TarpitAfterWait::class,
            'forget_after_seconds' => 'integer',
            'takes_gym_no_shows' => 'boolean',
            'not_found_count' => 'integer',
            'not_found_within_seconds' => 'integer',
            'burst_count' => 'integer',
            'burst_within_seconds' => 'integer',
        ];
    }
}
