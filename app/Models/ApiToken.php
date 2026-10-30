<?php

namespace App\Models;

use App\Enums\ApiAbility;
use Carbon\CarbonInterface;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A token an administrator made by hand, in Admin, so an AI agent can use the MCP server as them on
 * one of their teams, or on all of them (no team). Only its SHA-256 is stored: the token itself is
 * shown once, when it's made.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $team_id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Team|null $team
 */
#[Fillable(['user_id', 'team_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * Every token starts with this, so secret scanners (and people) can tell what a leaked one is.
     */
    public const string PREFIX = 'ntb_';

    /**
     * The random part's length, after the prefix.
     */
    public const int SECRET_LENGTH = 48;

    /**
     * Make a token, returning the model and the plain token (the only time it exists). Without a team,
     * it works on every team its user is on.
     *
     * @param  list<ApiAbility>  $abilities
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, ?Team $team, string $name, array $abilities, ?CarbonInterface $expiresAt = null): array
    {
        $plainText = self::PREFIX.Str::random(self::SECRET_LENGTH);

        $token = self::create([
            'user_id' => $user->id,
            'team_id' => $team?->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainText),
            'abilities' => collect([ApiAbility::Read, ...$abilities])->map->value->unique()->values()->all(),
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plainText];
    }

    /**
     * Determine if a string is shaped like one of our tokens, without asking the database.
     */
    public static function looksValid(string $plainText): bool
    {
        return strlen($plainText) === strlen(self::PREFIX) + self::SECRET_LENGTH
            && str_starts_with($plainText, self::PREFIX)
            && ctype_alnum(substr($plainText, strlen(self::PREFIX)));
    }

    /**
     * Find the unexpired token a plain token belongs to.
     */
    public static function findByPlainText(string $plainText): ?self
    {
        if (! self::looksValid($plainText)) {
            return null;
        }

        return self::query()
            ->with(['user.administrator', 'team'])
            ->where('token_hash', hash('sha256', $plainText))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * Determine if the token was given an ability.
     */
    public function allows(ApiAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Determine if the token works on all of its user's teams, rather than one.
     */
    public function coversAllTeams(): bool
    {
        return $this->team_id === null;
    }

    /**
     * The teams the token works on, by name: its own, or every team its user is on.
     *
     * @return EloquentCollection<int, Team>
     */
    public function teams(): EloquentCollection
    {
        if (! $this->coversAllTeams()) {
            return new EloquentCollection(array_filter([$this->team]));
        }

        return $this->user->teams()->orderBy('name')->get();
    }

    /**
     * Determine if the token may do something on a team (its own when not given): it was given the
     * ability, and its user (still) has the team permission that goes with it there. A token for all
     * teams, asked about no team in particular, may when its user may on any of them.
     */
    public function canOnTeam(ApiAbility $ability, ?Team $team = null): bool
    {
        if (! $this->allows($ability)) {
            return false;
        }

        $permission = $ability->teamPermission();
        $team ??= $this->team;

        if ($permission === null) {
            return true;
        }

        if ($team === null) {
            return $this->teams()->contains(fn (Team $team) => $this->user->hasTeamPermission($team, $permission));
        }

        return $this->user->hasTeamPermission($team, $permission);
    }

    /**
     * Note that the token was used, at most once a minute so a busy agent doesn't write on every call.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
