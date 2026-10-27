<?php

namespace NiceTry\Lockdown\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shared origin in a lockdown's pool (App\Support\SharedOrigins): every site of it, now or later, is
 * locked unless released on its own. The pool holds the origin's id, which sites carry as
 * domains.origin_id, so it keeps covering them whether or not anything keeps shared origins right now.
 *
 * @property int $origin_id
 * @property int $lockdown_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lockdown $lockdown
 */
#[Fillable(['origin_id'])]
class PooledOrigin extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lockdown_origins';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'origin_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Get the lockdown whose pool the origin is in.
     *
     * @return BelongsTo<Lockdown, $this>
     */
    public function lockdown(): BelongsTo
    {
        return $this->belongsTo(Lockdown::class);
    }
}
