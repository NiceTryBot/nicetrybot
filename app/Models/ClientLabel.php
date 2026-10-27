<?php

namespace App\Models;

use App\Enums\LabelKind;
use Database\Factories\ClientLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A name a team gave one value it recognises (an IP, a TLS fingerprint or a user agent), shown in its
 * place wherever that value appears.
 *
 * @property int $id
 * @property int $team_id
 * @property LabelKind $kind
 * @property string $value
 * @property string $value_hash
 * @property string $name
 * @property bool $is_mine Whether the value is the team's own traffic, hidden from Activity by default.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['kind', 'value', 'name', 'is_mine'])]
class ClientLabel extends Model
{
    /** @use HasFactory<ClientLabelFactory> */
    use HasFactory;

    /**
     * Bootstrap the model: keep the value's hash, which makes a value unique per team and kind.
     */
    protected static function booted(): void
    {
        static::saving(fn (ClientLabel $label) => $label->value_hash = md5($label->value));
    }

    /**
     * Get the team the label belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LabelKind::class,
            'is_mine' => 'boolean',
        ];
    }
}
