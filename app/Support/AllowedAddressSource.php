<?php

namespace App\Support;

/**
 * Something that names IP addresses and CIDR ranges that must never meet an enforcement: a visitor on
 * one is never sent to the gym, held in a tarpit, blocked, or dropped in the blackhole. An add-on lists
 * its sources in its service provider ($allowedAddresses), tagged in the container under
 * AllowedAddresses::TAG; the core gathers every on add-on's together (AllowedAddresses).
 *
 * The ranges are read on the hot path (the gym check, the tarpit and blackhole feeds), so a source
 * keeps allowedRanges() cheap, and returns ranges already written the one way the matcher and the edge
 * expect: a bare IP, or a CIDR range on its network address, IPv6 compressed.
 */
interface AllowedAddressSource
{
    /**
     * The bare IPs and CIDR ranges this source allows, e.g. ['203.0.113.4', '66.249.64.0/27'].
     *
     * @return list<string>
     */
    public function allowedRanges(): array;
}
