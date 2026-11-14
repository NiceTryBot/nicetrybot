<?php

namespace NiceTry\SwarmMode\Models;

use App\Models\EdgeRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link from a rule to Swarm Mode, made by the rule's SWARM clause: the sites and paths its scope
 * verdicts cover are in Swarm Mode while they last. One row at most per rule, gone with it.
 *
 * @property int $id
 * @property int $edge_rule_id
 * @property CarbonImmutable|null $created_at
 * @property-read EdgeRule $rule
 */
#[Fillable(['edge_rule_id'])]
class SwarmRule extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<EdgeRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EdgeRule::class, 'edge_rule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
