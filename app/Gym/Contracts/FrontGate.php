<?php

namespace App\Gym\Contracts;

use App\Data\GymVisitor;
use App\Models\Domain;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stage a package runs in the gym check after the allow list has had its say but before any rule is
 * reached: the place the Bot Bouncer add-on holds a blatant bot at the door. Gates are tagged in the
 * container under App\Gym\FrontGates::TAG and asked in order; the first to answer wins, and the request
 * it challenges never reaches the rules. The visitor and the enforcing domain are already worked out,
 * so a gate reads them rather than the raw X-NiceTry-* headers.
 */
interface FrontGate
{
    /**
     * Hold this visitor with a challenge of the gate's own, or return null to let them carry on to the
     * rules. The request carries only the gym's own cookies, which a gate's pass may be among.
     */
    public function handle(Request $request, Domain $domain, GymVisitor $visitor): ?Response;
}
