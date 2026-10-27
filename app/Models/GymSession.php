<?php

namespace App\Models;

use App\Data\ClientProfile;
use App\Enums\GymExit;
use App\Enums\GymPassState;
use App\Enums\GymSessionStatus;
use App\Gym\ObstacleState;
use Carbon\CarbonImmutable;
use Database\Factories\GymSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * One visitor's trip through the gym. The pipeline isn't stored here: it's rebuilt from the session's
 * routines on every request, so edits reach visitors mid-workout. What's stored is the visitor's
 * progress per obstacle (keyed by the obstacle entry's id) and any obstacles added by escalation.
 *
 * A preview session carries a draft of the routine being edited instead of live routines.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $domain_id
 * @property string $token
 * @property bool $is_preview
 * @property GymSessionStatus $status
 * @property GymExit|null $exit_action
 * @property string|null $destination
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property string|null $ja4
 * @property int|null $client_hints_id The client hints the visitor's own browser sent the gym.
 * @property string|null $country_code
 * @property int|null $asn
 * @property array<string, array<string, mixed>> $progress
 * @property list<array{key: string, type: string, after: string}> $escalations
 * @property list<array{id: int|null, name: string, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit: int, pass_seconds: int}>|null $draft
 * @property int $hashes
 * @property GymPassState|null $pass_state Null while a one-try pass is fresh.
 * @property list<array{ip: string|null, ja4: string|null, hints?: string|null}>|null $attempt_clients Distinct clients the edge saw make attempts, with the client hints each sent.
 * @property-read ClientHint|null $clientHint
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team $team
 * @property-read Domain|null $domain
 */
#[Fillable([
    'domain_id',
    'token',
    'is_preview',
    'status',
    'exit_action',
    'destination',
    'client_ip',
    'user_agent',
    'ja4',
    'client_hints_id',
    'country_code',
    'asn',
    'progress',
    'escalations',
    'draft',
    'hashes',
    'attempt_clients',
    'pass_state',
    'started_at',
    'last_seen_at',
    'finished_at',
])]
class GymSession extends Model
{
    /** @use HasFactory<GymSessionFactory> */
    use HasFactory;

    /**
     * The cookie a cleared visitor carries back to the edge.
     */
    public const string PASS_COOKIE = '_q_pass';

    /**
     * How many characters of the session's token make the reference a visitor can quote to the team.
     */
    public const int REFERENCE_LENGTH = 10;

    /**
     * When both edges began passing visitors' client hints to the gym check (UTC). A session started
     * since then without hints had a visitor that sent none; one from before may just not have been told.
     */
    public const string CLIENT_HINTS_SINCE = '2026-10-24 20:03:00';

    /**
     * The cookie that lets a visitor who reloads mid-workout carry on with the same session.
     */
    public const string SESSION_COOKIE = '_q_session';

    /**
     * Get the team whose gym the visitor is in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the domain the visitor was trying to reach.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get the client hints the visitor's own browser sent the gym.
     *
     * @return BelongsTo<ClientHint, $this>
     */
    public function clientHint(): BelongsTo
    {
        return $this->belongsTo(ClientHint::class, 'client_hints_id');
    }

    /**
     * Get the routines the visitor matched on entry.
     *
     * @return BelongsToMany<GymRoutine, $this>
     */
    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(GymRoutine::class);
    }

    /**
     * Limit to real visits, leaving out admins' previews.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->where('is_preview', false);
    }

    /**
     * Get the visitor's progress on one obstacle.
     */
    public function state(string $stepKey): ObstacleState
    {
        return ObstacleState::fromArray($this->progress[$stepKey] ?? []);
    }

    /**
     * Record the visitor's progress on one obstacle. Call save() afterwards.
     */
    public function putState(string $stepKey, ObstacleState $state): void
    {
        $this->progress = [...$this->progress, $stepKey => $state->toArray()];
    }

    /**
     * Remember a client the edge saw making an attempt, once per IP, fingerprint and client hints. Call
     * save() afterwards. Only the first few distinct clients are kept: that's enough to tell work handed off.
     */
    public function recordAttemptClient(?string $ip, ?string $ja4, ?string $hints = null): void
    {
        $client = ['ip' => $ip, 'ja4' => $ja4, 'hints' => $hints];
        $clients = $this->attempt_clients ?? [];

        if (! in_array($client, $clients, true) && count($clients) < 10) {
            $this->attempt_clients = [...$clients, $client];
        }
    }

    /**
     * Get the reference the gym shows the visitor, so the team can find this visit when they get in
     * touch: the start of the session's token, which the visitor's own gym URL already carries.
     */
    public function reference(): string
    {
        return substr($this->token, 0, self::REFERENCE_LENGTH);
    }

    /**
     * Get what the gym shows a visitor who needs help getting through: the team's contact (when it has
     * one), where it links, and the reference to quote. Null when the team shows no contact.
     *
     * @return array{contact: string, href: string, reference: string}|null
     */
    public function visitorHelp(): ?array
    {
        $settings = VisitorDataSetting::for($this->team);
        $href = $settings->contactHref($this->reference());

        return $href === null ? null : ['contact' => (string) $settings->contact, 'href' => $href, 'reference' => $this->reference()];
    }

    /**
     * Keep the client hints a request to the gym carried, when it came from the visitor who entered
     * (a request from another IP is a hand-off, kept with that attempt instead) and none are kept yet.
     * The edge's gym check doesn't pass them on, so the first gym page or attempt is where they're
     * heard. Call save() afterwards.
     */
    public function rememberClientHints(?string $ip, ?string $hints): void
    {
        if ($hints !== null && $this->client_hints_id === null && ($ip === null || $ip === $this->client_ip)) {
            $this->client_hints_id = ClientHint::idFor($hints);
        }
    }

    /**
     * Get what the visitor's client claims and is, as one IP made attempts for it: the user agent the
     * visitor entered with, the TLS fingerprint and client hints that IP attempted with (or the
     * visitor's own), and its network.
     */
    public function clientProfile(?string $attemptIp = null): ClientProfile
    {
        $attempt = collect($this->attempt_clients ?? [])->firstWhere('ip', $attemptIp);

        // An attempt the edge vouched for since attempts kept hints says whether that client sent any.
        if ($attempt !== null && array_key_exists('hints', $attempt)) {
            return ClientProfile::of($this->user_agent, $attempt['ja4'] ?? $this->ja4, $attempt['hints'], $this->asn, hintsHeard: true);
        }

        return ClientProfile::of($this->user_agent, $attempt['ja4'] ?? $this->ja4, $this->clientHint?->value, $this->asn, hintsHeard: (bool) $this->started_at?->greaterThanOrEqualTo(self::CLIENT_HINTS_SINCE));
    }

    /**
     * Determine if attempts came from a different IP than the visitor who entered, as when a bot hands
     * the work to another machine. The TLS fingerprint isn't compared: one browser's JA4 changes when
     * it moves from TCP to HTTP/3, and between HTTP/3 connections.
     */
    public function switchedClients(): bool
    {
        return collect($this->attempt_clients ?? [])->contains(fn (array $client) => $client['ip'] !== $this->client_ip);
    }

    /**
     * Count the obstacles the visitor has cleared.
     */
    public function clearedCount(): int
    {
        return collect($this->progress)->filter(fn (array $state) => ($state['cleared_at'] ?? null) !== null)->count();
    }

    /**
     * Determine if the visitor stopped training without finishing.
     */
    public function hasGivenUp(): bool
    {
        return $this->status === GymSessionStatus::Training
            && $this->last_seen_at->lt(now()->subMinutes((int) config('gym.idle_minutes')));
    }

    /**
     * Get the seconds the visitor has spent in the gym.
     */
    public function secondsInGym(): int
    {
        return (int) $this->started_at->diffInSeconds($this->finished_at ?? $this->last_seen_at);
    }

    /**
     * Get the signed pass cookie a cleared visitor carries back to the edge:
     * "<team>.<expires>.<token>.<hmac>", signed with the gym pass secret.
     */
    public function passCookie(int $seconds, bool $secure): Cookie
    {
        $payload = $this->team_id.'.'.now()->addSeconds($seconds)->getTimestamp().'.'.$this->token;
        $secret = (string) (config('gym.pass_secret') ?: config('app.key'));

        return cookie(
            self::PASS_COOKIE,
            $payload.'.'.hash_hmac('sha256', $payload, $secret),
            minutes: (int) ceil($seconds / 60),
            secure: $secure,
            sameSite: 'lax',
        );
    }

    /**
     * Find the session that issued a pass cookie, provided the pass is genuine, unexpired and the given
     * team's.
     */
    public static function fromPass(string $cookie, int $teamId): ?self
    {
        return self::isValidPass($cookie, $teamId)
            ? self::query()->where('team_id', $teamId)->where('token', explode('.', $cookie)[2])->first()
            : null;
    }

    /**
     * Determine if the session's pass allows one form submission rather than any pages: true when any
     * routine the visitor matched says so.
     */
    public function hasOneTryPass(): bool
    {
        return $this->routines()->where('one_try', true)->exists();
    }

    /**
     * Determine if a pass cookie is genuine, unexpired and issued by the given team's gym.
     */
    public static function isValidPass(string $cookie, int $teamId): bool
    {
        $parts = explode('.', $cookie);

        if (count($parts) !== 4 || (int) $parts[0] !== $teamId || (int) $parts[1] <= now()->getTimestamp()) {
            return false;
        }

        $secret = (string) (config('gym.pass_secret') ?: config('app.key'));

        return hash_equals(hash_hmac('sha256', implode('.', array_slice($parts, 0, 3)), $secret), $parts[3]);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_preview' => 'boolean',
            'status' => GymSessionStatus::class,
            'exit_action' => GymExit::class,
            'asn' => 'integer',
            'progress' => 'array',
            'escalations' => 'array',
            'draft' => 'array',
            'hashes' => 'integer',
            'attempt_clients' => 'array',
            'pass_state' => GymPassState::class,
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
