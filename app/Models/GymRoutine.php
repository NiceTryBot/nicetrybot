<?php

namespace App\Models;

use App\Data\GymEntry;
use App\Enums\GymExit;
use Database\Factories\GymRoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A gym routine: who enters, the obstacles they go through in order, and where they exit. Every
 * enabled routine a visitor matches stacks into their pipeline, in position order.
 *
 * Obstacles are stored as the pipeline itself: each entry has a stable id (so a visitor's progress
 * survives reordering and edits), an obstacle type from the registry, its config, and the obstacle
 * to add when this one flags the visitor as automated.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property bool $enabled
 * @property int $position
 * @property bool $match_all
 * @property list<array{field: string, operator: string, values: list<string>}> $conditions
 * @property list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}> $obstacles
 * @property GymExit $exit_action
 * @property int $pass_seconds
 * @property bool $one_try A pass allows one form submission rather than any pages until it expires.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['name', 'enabled', 'position', 'match_all', 'conditions', 'obstacles', 'exit_action', 'pass_seconds', 'one_try'])]
class GymRoutine extends Model
{
    /** @use HasFactory<GymRoutineFactory> */
    use HasFactory;

    /**
     * How long a cleared visitor can skip the gym, in seconds => label.
     *
     * @var array<int, string>
     */
    public const array PASS_DURATIONS = [
        60 => '1 minute',
        300 => '5 minutes',
        600 => '10 minutes',
        900 => '15 minutes',
        3600 => '1 hour',
        21600 => '6 hours',
        86400 => '1 day',
        604800 => '1 week',
    ];

    /**
     * Get the team the routine belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the gym sessions the routine was part of.
     *
     * @return BelongsToMany<GymSession, $this>
     */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(GymSession::class);
    }

    /**
     * Get who enters the routine.
     */
    public function entry(): GymEntry
    {
        return GymEntry::fromArray($this->conditions, $this->match_all);
    }

    /**
     * Get the routine as the pipeline builds it: what a preview draft also looks like.
     *
     * @return array{id: int|null, name: string, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit: int, pass_seconds: int}
     */
    public function blueprint(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'obstacles' => $this->obstacles,
            'exit' => $this->exit_action->value,
            'pass_seconds' => $this->pass_seconds,
        ];
    }

    /**
     * Get how long a cleared visitor skips the gym, e.g. "1 hour".
     */
    public function passLabel(): string
    {
        return __(self::PASS_DURATIONS[$this->pass_seconds] ?? ':count seconds', ['count' => $this->pass_seconds]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'position' => 'integer',
            'match_all' => 'boolean',
            'conditions' => 'array',
            'obstacles' => 'array',
            'exit_action' => GymExit::class,
            'pass_seconds' => 'integer',
            'one_try' => 'boolean',
        ];
    }
}
