<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use App\Enums\PathCategory;
use Database\Factories\RequestPathFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A distinct (already redacted) request path, shared by every request for it.
 *
 * @property int $id
 * @property string $hash
 * @property string $path
 * @property PathCategory|null $category
 * @property int|null $categorized_by Who chose the category by hand; null when the patterns decide.
 * @property Carbon|null $categorized_at When someone chose the category by hand. Patterns never override it.
 */
#[Fillable(['hash', 'path', 'category', 'categorized_by', 'categorized_at'])]
class RequestPath extends Model
{
    /** @use HasFactory<RequestPathFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the id for a path, recording it if it's new.
     */
    public static function idFor(string $path): int
    {
        return app(ResolveLookupIds::class)->paths([$path])[$path];
    }

    /**
     * Determine if someone chose the path's category by hand.
     */
    public function isCategorizedByHand(): bool
    {
        return $this->categorized_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PathCategory::class,
            'categorized_at' => 'datetime',
        ];
    }
}
