<?php

namespace NiceTry\Tarpit\Models;

use App\Enums\RequestAction;
use App\Models\EdgeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitReason;

/**
 * What a tarpit did to one request, as the edge logged it: why the client was in, which try it was,
 * and how long it was held.
 *
 * @property int $id
 * @property int $edge_request_id
 * @property int|null $tarpit_id Null once the tarpit is deleted.
 * @property TarpitReason $reason
 * @property TarpitOutcome $outcome
 * @property int $try
 * @property int $held_ms
 * @property CarbonImmutable|null $created_at
 * @property-read Tarpit|null $tarpit
 * @property-read EdgeRequest $edgeRequest
 */
#[Fillable(['edge_request_id', 'tarpit_id', 'reason', 'outcome', 'try', 'held_ms', 'created_at'])]
class TarpitHold extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Tarpit, $this>
     */
    public function tarpit(): BelongsTo
    {
        return $this->belongsTo(Tarpit::class);
    }

    /**
     * @return BelongsTo<EdgeRequest, $this>
     */
    public function edgeRequest(): BelongsTo
    {
        return $this->belongsTo(EdgeRequest::class);
    }

    /**
     * Determine if the tarpit itself answered the request: a hang-up or a client that left (logged with
     * no status), a 404, or a 429. A request it held and let on, then refused further in (the PHP guard's
     * 403, a lockdown's 503), was blocked by that, not by the tarpit.
     */
    public function answeredRequest(): bool
    {
        return $this->edgeRequest->action === RequestAction::Blocked
            && in_array($this->edgeRequest->status_code, [0, 404, 429], true);
    }

    protected function casts(): array
    {
        return [
            'reason' => TarpitReason::class,
            'outcome' => TarpitOutcome::class,
            'try' => 'integer',
            'held_ms' => 'integer',
        ];
    }
}
