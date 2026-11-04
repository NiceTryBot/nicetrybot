<?php

namespace App\Support;

use App\Models\Team;

/**
 * A client add-on that says which IPs to strike through where clients are listed, because they aren't
 * getting through any more. Tagged under ClientAddons::TAG like any client add-on.
 */
interface ClientStriker
{
    /**
     * Which of a page's IPs to strike through.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public function struckIps(Team $team, array $ips): array;
}
