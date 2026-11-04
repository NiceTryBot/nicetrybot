<?php

namespace NiceTry\DynamicSubdomains\Models;

use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subdomain a domain added when it was first visited: domain_id is the subdomain, a domain of its
 * own, and parent_domain_id the domain it was found under.
 *
 * @property int $id
 * @property int $parent_domain_id
 * @property int $domain_id
 * @property CarbonImmutable|null $created_at
 * @property-read Domain $parent
 * @property-read Domain $domain
 */
#[Fillable(['parent_domain_id', 'domain_id'])]
class DynamicSubdomain extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'parent_domain_id');
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
