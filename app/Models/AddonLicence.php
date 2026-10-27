<?php

namespace App\Models;

use App\Addons\Licences;
use Carbon\CarbonInterface;
use Database\Factories\AddonLicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The licence this installation holds for a paid add-on, by its slug, and what the marketplace last
 * said about it. Without a key (status missing) the row marks when the add-on was first seen, which
 * starts its trial; the trial never restarts, so removing a key doesn't buy more time. Saving or
 * deleting a row tells the licences registry, so a change shows from the next request.
 *
 * @property int $id
 * @property string $slug
 * @property string|null $key
 * @property string|null $licence_id
 * @property string|null $licensed_to
 * @property Carbon|null $issued_on
 * @property Carbon|null $updates_until
 * @property string $status
 * @property Carbon|null $checked_at
 * @property Carbon|null $first_seen_at
 * @property int|null $entered_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $enteredBy
 */
#[Fillable(['slug', 'key', 'licence_id', 'licensed_to', 'issued_on', 'updates_until', 'status', 'checked_at', 'first_seen_at', 'entered_by_id'])]
class AddonLicence extends Model
{
    /** @use HasFactory<AddonLicenceFactory> */
    use HasFactory;

    public const string MISSING = 'missing';

    public const string VALID = 'valid';

    public const string REVOKED = 'revoked';

    protected static function booted(): void
    {
        static::saved(fn () => app(Licences::class)->forget());
        static::deleted(fn () => app(Licences::class)->forget());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_id');
    }

    public function hasKey(): bool
    {
        return $this->key !== null;
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::REVOKED;
    }

    /**
     * Determine if the licence still gets updates: a key is good for ever, updates for a year.
     */
    public function getsUpdates(): bool
    {
        return $this->isValid() && $this->updates_until !== null && $this->updates_until->endOfDay()->isFuture();
    }

    /**
     * When the trial ends: trial_days after the add-on was first seen here.
     */
    public function trialEndsAt(): ?CarbonInterface
    {
        return $this->first_seen_at?->copy()->addDays((int) config('addons.licensing.trial_days'));
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
            'updates_until' => 'date',
            'checked_at' => 'datetime',
            'first_seen_at' => 'datetime',
        ];
    }
}
