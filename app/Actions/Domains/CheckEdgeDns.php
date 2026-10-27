<?php

namespace App\Actions\Domains;

use App\Data\EdgeDnsCheck;
use App\Models\Domain;

class CheckEdgeDns
{
    public function __construct(private LookUpDnsRecords $lookUpDnsRecords)
    {
        //
    }

    /**
     * Look up where the domain's public DNS currently sends visitors.
     */
    public function handle(Domain $domain): EdgeDnsCheck
    {
        $addresses = $this->lookUpDnsRecords->handle($domain->hostname);

        return new EdgeDnsCheck(
            hostname: $domain->hostname,
            ipv4Addresses: $addresses['ipv4'],
            ipv6Addresses: $addresses['ipv6'],
        );
    }
}
