<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day's gym totals for a team, rolled up so the gym page needn't scan every session.
 *
 * @property int $team_id
 * @property Carbon $day
 * @property int $entered
 * @property int $cleared
 * @property int $turned_away
 * @property int $gave_up
 * @property int $hashes
 */
class GymTeamDailyMetric extends Model
{
    protected $fillable = [
        'team_id',
        'day',
        'entered',
        'cleared',
        'turned_away',
        'gave_up',
        'hashes',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
            'turned_away' => 'integer',
            'gave_up' => 'integer',
            'hashes' => 'integer',
        ];
    }
}
