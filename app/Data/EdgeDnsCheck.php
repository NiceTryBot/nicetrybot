<?php

namespace App\Data;

use App\Models\Domain;

/**
 * What a domain's public DNS resolved to when it was checked against the edge.
 */
readonly class EdgeDnsCheck
{
    /**
     * @param  list<string>  $ipv4Addresses
     * @param  list<string>  $ipv6Addresses
     */
    public function __construct(
        public string $hostname,
        public array $ipv4Addresses,
        public array $ipv6Addresses,
    ) {
        //
    }

    /**
     * Determine if every visitor reaches the edge. IPv6 records must be gone too, since the
     * edge only listens on IPv4 and any IPv6 visitor would go straight around it.
     */
    public function isPointedAtEdge(): bool
    {
        return $this->ipv4Addresses !== []
            && array_diff($this->ipv4Addresses, Domain::edgeAddresses()) === []
            && $this->ipv6Addresses === [];
    }

    /**
     * Explain the result in a sentence the domain page can show.
     */
    public function summary(): string
    {
        if ($this->isPointedAtEdge()) {
            return __(':hostname points at the edge.', ['hostname' => $this->hostname]);
        }

        if ($this->ipv4Addresses === [] && $this->ipv6Addresses === []) {
            return __('No records found for :hostname yet. DNS changes can take a few minutes to show up.', ['hostname' => $this->hostname]);
        }

        $strayIpv4Addresses = array_values(array_diff($this->ipv4Addresses, Domain::edgeAddresses()));

        if ($strayIpv4Addresses !== []) {
            return __(':hostname still resolves to :addresses. Replace those records with the ones above.', [
                'hostname' => $this->hostname,
                'addresses' => implode(', ', $strayIpv4Addresses),
            ]);
        }

        return __(':hostname also has IPv6 (AAAA) records: :addresses. Remove them, or IPv6 visitors will skip the edge.', [
            'hostname' => $this->hostname,
            'addresses' => implode(', ', $this->ipv6Addresses),
        ]);
    }
}
