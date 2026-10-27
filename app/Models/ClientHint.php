<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A distinct set of client hints, as Chromium-based browsers send them: the Sec-CH-UA brand list and
 * the Sec-CH-UA-Platform, joined as "<brands> | <platform>". Automated Chrome often gives itself away
 * here (a "HeadlessChrome" brand, or a platform that contradicts the user agent).
 *
 * @property int $id
 * @property string $hash
 * @property string $value
 */
#[Fillable(['hash', 'value'])]
class ClientHint extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Join the client hints a request carries (Sec-CH-UA and Sec-CH-UA-Platform) as "<brands> | <platform>",
     * or null when the client sent neither, as non-Chromium browsers and most scripts don't. Each is cut
     * to 300 characters, as the edge logs them.
     */
    public static function valueOf(?string $brands, ?string $platform): ?string
    {
        $brands = trim(mb_substr((string) $brands, 0, 300));
        $platform = trim(mb_substr((string) $platform, 0, 300));

        return $brands === '' && $platform === '' ? null : $brands.' | '.$platform;
    }

    /**
     * Get the id for a set of client hints, recording it if it's new.
     */
    public static function idFor(string $value): int
    {
        return app(ResolveLookupIds::class)->clientHints([$value])[$value];
    }
}
