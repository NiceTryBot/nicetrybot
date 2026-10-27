<?php

namespace App\Models;

use App\Enums\RuleAction;
use Database\Factories\VerdictFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rule's decision about one subject (an IP, a TLS fingerprint, or both), e.g. "banned for 30 days".
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $edge_rule_id
 * @property RuleAction $action
 * @property string|null $tag
 * @property string|null $client_ip
 * @property int|null $ja4_id
 * @property int $matched_requests
 * @property Carbon $first_matched_at
 * @property Carbon $last_matched_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $lifted_at
 * @property Carbon|null $created_at
 * @property-read EdgeRule|null $rule
 * @property-read TlsFingerprint|null $ja4Fingerprint
 */
#[Fillable(['team_id', 'edge_rule_id', 'action', 'tag', 'client_ip', 'ja4_id', 'matched_requests', 'first_matched_at', 'last_matched_at', 'expires_at', 'lifted_at'])]
class Verdict extends Model
{
    /** @use HasFactory<VerdictFactory> */
    use HasFactory;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the rule that reached the verdict.
     *
     * @return BelongsTo<EdgeRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EdgeRule::class, 'edge_rule_id');
    }

    /**
     * @return BelongsTo<TlsFingerprint, $this>
     */
    public function ja4Fingerprint(): BelongsTo
    {
        return $this->belongsTo(TlsFingerprint::class, 'ja4_id');
    }

    /**
     * Limit to verdicts still in force: not lifted and not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Determine if the verdict is still in force.
     */
    public function isActive(): bool
    {
        return $this->lifted_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RuleAction::class,
            'ja4_id' => 'integer',
            'matched_requests' => 'integer',
            'first_matched_at' => 'datetime',
            'last_matched_at' => 'datetime',
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }
}
