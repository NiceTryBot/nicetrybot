<?php

namespace App\Support;

use App\Enums\ProtectionMode;
use Carbon\CarbonInterface;

/**
 * One shared origin, as the core's pages and other add-ons see it: a backend serving many sites, each a
 * domain whose origin_id is the origin's id. It comes from whatever keeps shared origins
 * (App\Support\SharedOrigins).
 */
final readonly class SharedOrigin
{
    /**
     * @param  string  $url  Its page in the app.
     * @param  int  $siteCount  How many domains are its sites.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public string $originUrl,
        public ?string $originHostHeader,
        public ?string $authorizeUrl,
        public ProtectionMode $protectionMode,
        public int $siteCount,
        public ?CarbonInterface $updatedAt,
    ) {
        //
    }
}
