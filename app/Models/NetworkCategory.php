<?php

namespace App\Models;

use App\Enums\NetworkCategory as Category;
use Database\Factories\NetworkCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an autonomous system is for, and how much it announces. A side table rather than a column on
 * networks, since most of its rows (from App\Actions\Networks\LoadNetworkCategories) are networks that
 * have never sent traffic here. An administrator's correction (is_override) outlives any reload, and one
 * with no category says the network has none, however the data has it.
 *
 * @property int $asn
 * @property Category|null $category
 * @property int $ranges
 * @property int $addresses
 * @property bool $is_override
 * @property-read Network|null $network
 */
#[Fillable(['asn', 'category', 'ranges', 'addresses', 'is_override'])]
class NetworkCategory extends Model
{
    /** @use HasFactory<NetworkCategoryFactory> */
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
     * Get the network, once it has sent traffic here.
     *
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'asn', 'asn');
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
            'category' => Category::class,
            'ranges' => 'integer',
            'addresses' => 'integer',
            'is_override' => 'boolean',
        ];
    }
}
