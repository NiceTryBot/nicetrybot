<?php

namespace App\Models;

use Database\Factories\VisitorErasureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record that a team erased everything it held about one visitor, kept to show the request was
 * honoured. It names the visitor only by a keyed hash, so it doesn't undo the erasure: the same IP or
 * fingerprint hashes the same, which answers "was this visitor erased?" without saying who they were.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id Who asked for it.
 * @property string $subject_hash
 * @property string $subject_kind "ip", "ja4" or "both".
 * @property array<string, int>|null $deleted Rows deleted, by kind, once done.
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'user_id', 'subject_hash', 'subject_kind', 'deleted', 'completed_at'])]
class VisitorErasure extends Model
{
    /** @use HasFactory<VisitorErasureFactory> */
    use HasFactory;

    /**
     * Hash a visitor's IP and TLS fingerprint the way erasures keep them.
     */
    public static function hashSubject(?string $ip, ?string $ja4): string
    {
        return hash_hmac('sha256', strtolower(($ip ?? '').'|'.($ja4 ?? '')), (string) config('app.key'));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Count the rows the erasure deleted.
     */
    public function deletedCount(): int
    {
        return (int) array_sum($this->deleted ?? []);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
