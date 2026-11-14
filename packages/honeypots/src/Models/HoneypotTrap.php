<?php

namespace NiceTry\Honeypots\Models;

use App\Models\Domain;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use NiceTry\Honeypots\Database\Factories\HoneypotTrapFactory;
use NiceTry\Honeypots\Support\TrapPatterns;

/**
 * A team's honeypot trap: on every domain of the team (no domain) or on one, a request whose path fits
 * one of its patterns and that the site's origin answers with a 404 goes to its honeypot instead. A
 * path the site serves is never touched.
 *
 * @property int $id
 * @property int $team_id
 * @property int $honeypot_id
 * @property int|null $domain_id Null when it covers every domain of the team.
 * @property list<string> $patterns As typed: globs, and regular expressions starting re:.
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team $team
 * @property-read Honeypot $honeypot
 * @property-read Domain|null $domain
 */
#[Fillable(['team_id', 'honeypot_id', 'domain_id', 'patterns', 'enabled'])]
class HoneypotTrap extends Model
{
    /** @use HasFactory<HoneypotTrapFactory> */
    use HasFactory;

    /**
     * Where the edge's list is kept between polls.
     */
    public const string FEED_CACHE_KEY = 'honeypots:feed';

    protected static function newFactory(): HoneypotTrapFactory
    {
        return HoneypotTrapFactory::new();
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
     * @return BelongsTo<Honeypot, $this>
     */
    public function honeypot(): BelongsTo
    {
        return $this->belongsTo(Honeypot::class);
    }

    /**
     * The one domain the trap covers, when it doesn't cover them all.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Determine if the trap covers every domain of the team.
     */
    public function isTeamWide(): bool
    {
        return $this->domain_id === null;
    }

    /**
     * The trap's globs, each with its leading slash.
     *
     * @return list<string>
     */
    public function globs(): array
    {
        return TrapPatterns::globs($this->patterns);
    }

    /**
     * The trap's regular expressions.
     *
     * @return list<string>
     */
    public function regexes(): array
    {
        return TrapPatterns::regexes($this->patterns);
    }

    protected function casts(): array
    {
        return [
            'patterns' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
