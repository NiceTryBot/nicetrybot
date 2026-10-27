<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use Database\Factories\BotTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The name of an edge rule that tagged a request, e.g. "probe".
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class BotTag extends Model
{
    /** @use HasFactory<BotTagFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the id for a tag, recording it if it's new.
     */
    public static function idFor(string $name): int
    {
        return app(ResolveLookupIds::class)->botTags([$name])[$name];
    }
}
