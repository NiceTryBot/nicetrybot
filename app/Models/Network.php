<?php

namespace App\Models;

use App\Enums\NetworkKind;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An autonomous system (ISP, cloud or hosting provider), keyed by its ASN.
 *
 * @property int $asn
 * @property string $name
 * @property NetworkKind $kind
 */
#[Fillable(['asn', 'name', 'kind'])]
class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'asn';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Determine if the network is a cloud or hosting provider.
     */
    public function isHosting(): bool
    {
        return $this->kind === NetworkKind::Hosting;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asn' => 'integer',
            'kind' => NetworkKind::class,
        ];
    }
}
