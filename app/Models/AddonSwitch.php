<?php

namespace App\Models;

use App\Addons\AddonSwitchboard;
use Database\Factories\AddonSwitchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What an administrator changed about an add-on, by its slug: turned it off (at once, on every
 * server) or asked for it to be removed (by the next deploy). Without a row an add-on is on, as
 * deployed. Saving or deleting one tells the switchboard, so the change shows from the next request.
 *
 * @property int $id
 * @property string $slug
 * @property Carbon|null $turned_off_at
 * @property int|null $turned_off_by_id
 * @property Carbon|null $removal_requested_at
 * @property int|null $removal_requested_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $turnedOffBy
 * @property-read User|null $removalRequestedBy
 */
#[Fillable(['slug', 'turned_off_at', 'turned_off_by_id', 'removal_requested_at', 'removal_requested_by_id'])]
class AddonSwitch extends Model
{
    /** @use HasFactory<AddonSwitchFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => app(AddonSwitchboard::class)->forget());
        static::deleted(fn () => app(AddonSwitchboard::class)->forget());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function turnedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'turned_off_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removalRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removal_requested_by_id');
    }

    public function isOff(): bool
    {
        return $this->turned_off_at !== null;
    }

    public function isAwaitingRemoval(): bool
    {
        return $this->removal_requested_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'turned_off_at' => 'datetime',
            'removal_requested_at' => 'datetime',
        ];
    }
}
