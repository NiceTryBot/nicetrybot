<?php

namespace App\Actions\Domains;

class LookUpDnsRecords
{
    /**
     * Resolve the IPv4 and IPv6 addresses a hostname points at, following any CNAME chain.
     *
     * A failed or empty lookup yields no addresses rather than an error, since a record
     * that hasn't propagated yet is the normal state while someone is changing DNS.
     *
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    public function handle(string $hostname): array
    {
        return [
            'ipv4' => $this->resolve($hostname, DNS_A, 'ip'),
            'ipv6' => $this->resolve($hostname, DNS_AAAA, 'ipv6'),
        ];
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $hostname, int $type, string $field): array
    {
        $records = @dns_get_record($hostname, $type);

        if (! is_array($records)) {
            return [];
        }

        return collect($records)->pluck($field)->filter()->unique()->sort()->values()->all();
    }
}
