<?php

namespace App\Support;

use App\Models\Domain;

/**
 * Something that adds a domain on the spot for a hostname the edge hasn't heard of, like an add-on's
 * dynamic subdomains (foo.site joining as a domain of its own because site lets it). The edge asks
 * about such a hostname when it wants a certificate for it (/caddy/ask) and when a request for it
 * arrives (/caddy/resolve-origin). Claimers are tagged in the container under HostnameClaimers::TAG,
 * and asked after shared origins.
 */
interface HostnameClaimer
{
    /**
     * The domain to serve a hostname no domain has, added on the spot, or null to leave it unknown. The
     * hostname comes valid and in lower case, and is asked about again until a domain has it, so a
     * domain added once is found the next time rather than claimed again.
     */
    public function claim(string $hostname): ?Domain;
}
