<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day's entered and cleared counts for one routine, rolled up for the gym page.
 *
 * @property int $gym_routine_id
 * @property int $team_id
 * @property Carbon $day
 * @property int $entered
 * @property int $cleared
 */
class GymRoutineDailyMetric extends Model
{
    protected $fillable = [
        'gym_routine_id',
        'team_id',
        'day',
        'entered',
        'cleared',
    ];

    /**
     * @return BelongsTo<GymRoutine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(GymRoutine::class, 'gym_routine_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'entered' => 'integer',
            'cleared' => 'integer',
        ];
    }
}
