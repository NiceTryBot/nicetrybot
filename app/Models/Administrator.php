<?php

namespace App\Models;

use Database\Factories\AdministratorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user who runs this installation: they manage what's installation-wide, like add-ons, and who else
 * is an administrator. Being one says nothing about a team; that's a membership.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $granted_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $grantedBy
 */
#[Fillable(['user_id', 'granted_by_id'])]
class Administrator extends Model
{
    /** @use HasFactory<AdministratorFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}
