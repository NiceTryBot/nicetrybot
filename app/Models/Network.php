<?php

namespace App\Models;

use App\Enums\NetworkCategory as Category;
use App\Enums\NetworkKind;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An autonomous system (ISP, cloud or hosting provider), keyed by its ASN.
 *
 * @property int $asn
 * @property string $name
 * @property NetworkKind $kind
 * @property-read NetworkCategory|null $networkCategory
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
     * Get what the network is for, if the data or an administrator says.
     *
     * @return HasOne<NetworkCategory, $this>
     */
    public function networkCategory(): HasOne
    {
        return $this->hasOne(NetworkCategory::class, 'asn', 'asn');
    }

    /**
     * Get the category to show the network under: its own, or Hosting if only the curated list knows it.
     * Having a row with no category means an administrator said it has none. Load networkCategory first.
     */
    public function shownCategory(): ?Category
    {
        if ($this->networkCategory !== null) {
            return $this->networkCategory->category;
        }

        return $this->isHosting() ? Category::Hosting : null;
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
