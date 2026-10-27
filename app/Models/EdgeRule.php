<?php

namespace App\Models;

use App\Data\RuleScript;
use App\Enums\RuleAction;
use Database\Factories\EdgeRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A team's rule, written as a rule script (see RuleScript). Action and duration are copied from the
 * script on save so lists can show them without parsing.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $source
 * @property bool $enabled
 * @property RuleAction $action
 * @property int|null $duration_seconds
 * @property int|null $last_request_id
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['name', 'source', 'enabled', 'action', 'duration_seconds', 'last_request_id', 'last_evaluated_at'])]
class EdgeRule extends Model
{
    /** @use HasFactory<EdgeRuleFactory> */
    use HasFactory;

    /**
     * Get the team the rule belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the verdicts the rule has reached.
     *
     * @return HasMany<Verdict, $this>
     */
    public function verdicts(): HasMany
    {
        return $this->hasMany(Verdict::class);
    }

    /**
     * Parse the rule's script. Saved rules always parse, because they're validated on save.
     */
    public function script(): RuleScript
    {
        return RuleScript::parse($this->source);
    }

    /**
     * Get the rule's duration compactly, e.g. "30d", "6h" or "forever".
     */
    public function durationLabel(): string
    {
        if ($this->duration_seconds === null) {
            return __('forever');
        }

        foreach ([86400 => 'd', 3600 => 'h', 60 => 'm'] as $size => $unit) {
            if ($this->duration_seconds % $size === 0) {
                return intdiv($this->duration_seconds, $size).$unit;
            }
        }

        return $this->duration_seconds.'s';
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
            'action' => RuleAction::class,
            'duration_seconds' => 'integer',
            'last_request_id' => 'integer',
            'last_evaluated_at' => 'datetime',
        ];
    }
}
