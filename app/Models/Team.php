<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, GymRoutine> $gymRoutines
 */
#[Fillable(['name', 'slug', 'is_personal'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all domains protected for this team.
     *
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Get the team's rules.
     *
     * @return HasMany<EdgeRule, $this>
     */
    public function edgeRules(): HasMany
    {
        return $this->hasMany(EdgeRule::class);
    }

    /**
     * Get the labels the team gave values it recognises (IPs, fingerprints, user agents).
     *
     * @return HasMany<ClientLabel, $this>
     */
    public function clientLabels(): HasMany
    {
        return $this->hasMany(ClientLabel::class);
    }

    /**
     * Get the live tails the team's members have on.
     *
     * @return HasMany<LiveTail, $this>
     */
    public function liveTails(): HasMany
    {
        return $this->hasMany(LiveTail::class);
    }

    /**
     * Get the Activity searches the team saved, by name.
     *
     * @return HasMany<SavedSearch, $this>
     */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class)->orderBy('name')->orderBy('id');
    }

    /**
     * Get the verdicts the team's rules have reached.
     *
     * @return HasMany<Verdict, $this>
     */
    public function verdicts(): HasMany
    {
        return $this->hasMany(Verdict::class);
    }

    /**
     * Get the team's gym routines, in the order they stack.
     *
     * @return HasMany<GymRoutine, $this>
     */
    public function gymRoutines(): HasMany
    {
        return $this->hasMany(GymRoutine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Get the record of visitors the team has erased.
     *
     * @return HasMany<VisitorErasure, $this>
     */
    public function visitorErasures(): HasMany
    {
        return $this->hasMany(VisitorErasure::class);
    }

    /**
     * Get the visits the team's gym has hosted.
     *
     * @return HasMany<GymSession, $this>
     */
    public function gymSessions(): HasMany
    {
        return $this->hasMany(GymSession::class);
    }

    /**
     * Get all requests the edge has logged across this team's domains.
     *
     * @return HasManyThrough<EdgeRequest, Domain, $this>
     */
    public function edgeRequests(): HasManyThrough
    {
        return $this->hasManyThrough(EdgeRequest::class, Domain::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
