<?php

namespace NiceTry\DynamicSubdomains\Models;

use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NiceTry\DynamicSubdomains\Database\Factories\DynamicSubdomainParentFactory;

/**
 * A domain with dynamic subdomains turned on: a visit to a name one label under it, like foo.<hostname>,
 * adds that name as a domain of its own (App\Support\HostnameClaimer). Turning it off deletes the row;
 * the subdomains it added stay.
 *
 * @property int $id
 * @property int $domain_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Domain $domain
 */
#[Fillable(['domain_id'])]
class DynamicSubdomainParent extends Model
{
    /** @use HasFactory<DynamicSubdomainParentFactory> */
    use HasFactory;

    protected static function newFactory(): DynamicSubdomainParentFactory
    {
        return DynamicSubdomainParentFactory::new();
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The subdomains the domain added.
     *
     * @return HasMany<DynamicSubdomain, $this>
     */
    public function subdomains(): HasMany
    {
        return $this->hasMany(DynamicSubdomain::class, 'parent_domain_id', 'domain_id');
    }
}
