<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use App\Data\Ja4Fingerprint;
use App\Enums\TlsFingerprintType;
use Database\Factories\TlsFingerprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A distinct JA4 or JA3 TLS client fingerprint.
 *
 * @property int $id
 * @property TlsFingerprintType $type
 * @property string $value
 */
#[Fillable(['type', 'value'])]
class TlsFingerprint extends Model
{
    /** @use HasFactory<TlsFingerprintFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the id for a fingerprint, recording it if it's new.
     */
    public static function idFor(TlsFingerprintType $type, string $value): int
    {
        return app(ResolveLookupIds::class)->fingerprints($type, [$value])[$value];
    }

    /**
     * Get the decoded JA4, or null for a JA3.
     */
    public function ja4(): ?Ja4Fingerprint
    {
        return $this->type === TlsFingerprintType::Ja4 ? Ja4Fingerprint::tryFrom($this->value) : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TlsFingerprintType::class,
        ];
    }
}
