<?php

namespace App\Mcp\Tools\Domains;

use App\Models\Domain;
use App\Models\Team;
use App\Rules\Hostname;

/**
 * How the domain tools describe a domain to an agent: what it is, what DNS it needs, and what's left
 * to do. Never the origin secret or the verification token beyond the TXT record itself.
 */
final class DomainSummary
{
    /**
     * Find one of the team's domains by the hostname an agent typed.
     */
    public static function find(Team $team, string $hostname): ?Domain
    {
        return $team->domains()->where('hostname', Hostname::normalize($hostname))->first();
    }

    /**
     * The preview URL serves the site through the edge as though DNS had already moved, once the
     * domain is verified: the way to try it before any visitor goes through the edge.
     *
     * @return array{hostname: string, origin_url: string, status: string, verified: bool, protection_mode: string, serves_www: bool, dns_pointed_at_edge: bool, preview_url: string|null, page_url: string}
     */
    public static function of(Domain $domain): array
    {
        return [
            'hostname' => $domain->hostname,
            'origin_url' => $domain->origin_url,
            'status' => $domain->status->value,
            'verified' => $domain->verified_at !== null,
            'protection_mode' => $domain->protection_mode->value,
            'serves_www' => $domain->servesWww(),
            'dns_pointed_at_edge' => $domain->edge_dns_pointed_at !== null,
            'preview_url' => $domain->preview_hostname !== null ? 'https://'.$domain->preview_hostname : null,
            'page_url' => route('domains.show', ['current_team' => $domain->team->slug, 'domain' => $domain]),
        ];
    }

    /**
     * The TXT record that proves the team owns the domain.
     *
     * @return array{type: string, name: string, value: string}
     */
    public static function ownershipRecord(Domain $domain): array
    {
        return [
            'type' => 'TXT',
            'name' => $domain->verificationRecordName(),
            'value' => $domain->verificationRecordValue(),
        ];
    }

    /**
     * The records that send visitors to the edge: an A record for an apex domain (it can't have a
     * CNAME), a CNAME otherwise, and a CNAME for www. when the domain serves it.
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    public static function edgeRecords(Domain $domain): array
    {
        $records = $domain->isApex()
            ? array_map(fn (string $address) => ['type' => 'A', 'name' => $domain->hostname, 'value' => $address], Domain::edgeAddresses())
            : [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => Domain::edgeHostname()]];

        if ($domain->servesWww()) {
            $records[] = ['type' => 'CNAME', 'name' => 'www.'.$domain->hostname, 'value' => Domain::edgeHostname()];
        }

        return $records;
    }
}
