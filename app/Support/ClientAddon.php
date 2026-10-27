<?php

namespace App\Support;

use App\Models\Team;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Something a package adds to each client cell where clients are listed (Activity's requests and
 * clients), from outside the core. Add-ons are tagged in the container under ClientAddons::TAG.
 */
interface ClientAddon
{
    /**
     * Draw the add-on's part of the cell for a page of clients at once, so it can load what it needs in
     * one go. A client is an IP and a TLS fingerprint id; either is null when the list groups by the
     * other alone.
     *
     * @param  array<int, array{ip: string|null, ja4: int|null}>  $clients
     * @return array<int, Htmlable|null> Keyed like the clients given.
     */
    public function render(Team $team, array $clients): array;
}
