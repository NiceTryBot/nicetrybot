<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use Database\Factories\UserAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A distinct User-Agent header, logged as sent (not parsed).
 *
 * @property int $id
 * @property string $hash
 * @property string $value
 */
#[Fillable(['hash', 'value'])]
class UserAgent extends Model
{
    /** @use HasFactory<UserAgentFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the id for a user agent, recording it if it's new.
     */
    public static function idFor(string $value): int
    {
        return app(ResolveLookupIds::class)->userAgents([$value])[$value];
    }
}
