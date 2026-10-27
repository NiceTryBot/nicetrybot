<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A More domains pack: a key from the marketplace that adds 100 domains to this installation's free
 * 10 (DomainLimit), and what the marketplace last said about it. Packs stack, and don't expire; a
 * refund or chargeback revokes one.
 *
 * @property int $id
 * @property string $licence_id
 * @property string $key
 * @property string|null $licensed_to
 * @property Carbon|null $issued_on
 * @property string $status
 * @property Carbon|null $checked_at
 * @property int|null $entered_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $enteredBy
 */
#[Fillable(['licence_id', 'key', 'licensed_to', 'issued_on', 'status', 'checked_at', 'entered_by_id'])]
class DomainPack extends Model
{
    public const string VALID = 'valid';

    public const string REVOKED = 'revoked';

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_id');
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where('status', self::VALID);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'checked_at' => 'datetime',
        ];
    }
}
