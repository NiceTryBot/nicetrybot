<?php

namespace App\Models;

use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An Activity search a team saved under a name, like "Vesync public forms". It keeps the page's query
 * string (the WHERE, the question it was asked as, the period and the other filters), so opening it
 * brings the page back as it was.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $name
 * @property array<string, string|int> $parameters The Activity page's query string, e.g. ['q' => "status = 404", 'period' => '7d'].
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'name', 'parameters'])]
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
        ];
    }

    /**
     * Get the team the search belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the person who saved the search.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the Activity page's URL with the search applied.
     */
    public function url(): string
    {
        return route('activity.index', $this->parameters);
    }
}
