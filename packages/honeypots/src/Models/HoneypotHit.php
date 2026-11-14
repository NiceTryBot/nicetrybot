<?php

namespace NiceTry\Honeypots\Models;

use App\Models\EdgeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NiceTry\Honeypots\Enums\HoneypotOutcome;

/**
 * What a honeypot did with one trapped request, as the edge logged it: whether it answered, with which
 * status, and how long it took.
 *
 * @property int $id
 * @property int $edge_request_id
 * @property int|null $honeypot_id Null once the honeypot is deleted.
 * @property int|null $honeypot_trap_id Null once the trap is deleted.
 * @property HoneypotOutcome $outcome
 * @property int $status The honeypot's status, 0 when it didn't answer.
 * @property int $took_ms
 * @property CarbonImmutable|null $created_at
 * @property-read Honeypot|null $honeypot
 * @property-read HoneypotTrap|null $trap
 * @property-read EdgeRequest $edgeRequest
 */
#[Fillable(['edge_request_id', 'honeypot_id', 'honeypot_trap_id', 'outcome', 'status', 'took_ms', 'created_at'])]
class HoneypotHit extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Honeypot, $this>
     */
    public function honeypot(): BelongsTo
    {
        return $this->belongsTo(Honeypot::class);
    }

    /**
     * @return BelongsTo<HoneypotTrap, $this>
     */
    public function trap(): BelongsTo
    {
        return $this->belongsTo(HoneypotTrap::class, 'honeypot_trap_id');
    }

    /**
     * @return BelongsTo<EdgeRequest, $this>
     */
    public function edgeRequest(): BelongsTo
    {
        return $this->belongsTo(EdgeRequest::class);
    }

    protected function casts(): array
    {
        return [
            'outcome' => HoneypotOutcome::class,
            'status' => 'integer',
            'took_ms' => 'integer',
        ];
    }
}
