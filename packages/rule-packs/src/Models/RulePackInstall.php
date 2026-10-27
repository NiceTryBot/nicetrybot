<?php

namespace NiceTry\RulePacks\Models;

use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use NiceTry\RulePacks\Database\Factories\RulePackInstallFactory;
use NiceTry\RulePacks\Support\PackFingerprint;

/**
 * One of a team's rules or gym routines, as a rule pack installed it: which pack and item it came
 * from, at which of the pack's versions, and what it looked like then (its fingerprint), so an item
 * the team has edited since is left alone. The row goes when its rule or routine is deleted.
 *
 * @property int $id
 * @property int $team_id
 * @property string $pack
 * @property string $item
 * @property int $version
 * @property int|null $edge_rule_id
 * @property int|null $gym_routine_id
 * @property string $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read EdgeRule|null $rule
 * @property-read GymRoutine|null $routine
 */
#[Fillable(['team_id', 'pack', 'item', 'version', 'edge_rule_id', 'gym_routine_id', 'fingerprint'])]
class RulePackInstall extends Model
{
    /** @use HasFactory<RulePackInstallFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<EdgeRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EdgeRule::class, 'edge_rule_id');
    }

    /**
     * @return BelongsTo<GymRoutine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(GymRoutine::class, 'gym_routine_id');
    }

    /**
     * Determine if the team has changed the item since the pack wrote it. Switching it on or off
     * doesn't count.
     */
    public function isEdited(): bool
    {
        return $this->currentFingerprint() !== $this->fingerprint;
    }

    /**
     * The fingerprint of the item as it is now, or null when it's gone.
     */
    public function currentFingerprint(): ?string
    {
        $item = $this->rule ?? $this->routine;

        return $item === null ? null : PackFingerprint::of($item);
    }

    protected static function newFactory(): RulePackInstallFactory
    {
        return RulePackInstallFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'edge_rule_id' => 'integer',
            'gym_routine_id' => 'integer',
        ];
    }
}
