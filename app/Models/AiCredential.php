<?php

namespace App\Models;

use App\Enums\AiProvider;
use Database\Factories\AiCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's own API key for an AI provider, which features like the plain-English Activity search call
 * with. The key is encrypted at rest; only its hint is ever shown again.
 *
 * @property int $id
 * @property int $user_id
 * @property AiProvider $provider
 * @property string $secret The API key.
 * @property string $hint The key's start and last four characters, like "sk-ant-…x7Qa".
 * @property string|null $workspace_id The Anthropic workspace an organization-wide key bills to, like "wrkspc_01…".
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['provider', 'secret', 'hint', 'workspace_id', 'last_used_at'])]
#[Hidden(['secret'])]
class AiCredential extends Model
{
    /** @use HasFactory<AiCredentialFactory> */
    use HasFactory;

    /**
     * Get the hint shown for a key: enough to tell keys apart, never enough to use one.
     */
    public static function hintFor(string $key): string
    {
        return substr($key, 0, 7).'…'.substr($key, -4);
    }

    /**
     * Get the user the key belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'secret' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }
}
