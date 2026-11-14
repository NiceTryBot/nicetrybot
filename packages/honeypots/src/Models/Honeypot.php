<?php

namespace NiceTry\Honeypots\Models;

use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use NiceTry\Honeypots\Database\Factories\HoneypotFactory;

/**
 * A team's honeypot: an origin of its own (a Galah server, say) that answers the 404s its traps catch.
 * The edge asks it with `secret` as X-NiceTry-Origin-Secret, so it can refuse anyone else, and waits up
 * to `timeout_seconds` before sending the site's own 404 instead.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $origin
 * @property string $secret
 * @property int $timeout_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, HoneypotTrap> $traps
 */
#[Fillable(['team_id', 'name', 'origin', 'timeout_seconds'])]
#[Hidden(['secret'])]
class Honeypot extends Model
{
    /** @use HasFactory<HoneypotFactory> */
    use HasFactory;

    /**
     * The longest the edge waits for a honeypot, in seconds.
     */
    public const int MAX_TIMEOUT = 120;

    protected static function newFactory(): HoneypotFactory
    {
        return HoneypotFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Honeypot $honeypot) {
            $honeypot->secret ??= self::newSecret();
        });
        static::saved(fn () => HoneypotTrap::forgetFeed());
        static::deleted(fn () => HoneypotTrap::forgetFeed());
    }

    /**
     * A new secret for a honeypot: letters and digits only, so it fits any header and config file.
     */
    public static function newSecret(): string
    {
        return Str::random(48);
    }

    /**
     * Give the honeypot a new secret. The edge sends it within seconds; the honeypot must learn it too.
     */
    public function rotateSecret(): void
    {
        $this->secret = self::newSecret();
        $this->save();
    }

    /**
     * The rules for a honeypot's editor.
     *
     * @return array<string, list<mixed>>
     */
    public static function editorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'origin' => ['required', 'string', 'max:2048', 'url:http,https'],
            'timeoutSeconds' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TIMEOUT],
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<HoneypotTrap, $this>
     */
    public function traps(): HasMany
    {
        return $this->hasMany(HoneypotTrap::class);
    }

    /**
     * The origin as the edge takes it: scheme and host (and port) only.
     */
    public function originBase(): string
    {
        $parts = parse_url($this->origin);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
        ];
    }
}
