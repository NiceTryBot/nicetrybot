<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Data\GymVisitor;
use App\Data\LiveRequest;
use App\Gym\Contracts\FrontGate;
use App\Models\Domain;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gates packages run between the allow list and the rules in the edge's gym check. Without any, the
 * check goes straight from the allow list to the rules, as it always has.
 */
class FrontGates
{
    /**
     * The container tag gates register under.
     */
    public const string TAG = 'gym.front-gates';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Get the first gate's answer for this visitor, or null when none holds them.
     */
    public function answer(Request $request, Domain $domain, GymVisitor $visitor, LiveRequest $live): ?Response
    {
        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $gate) {
            if ($gate instanceof FrontGate && ($response = $gate->handle($request, $domain, $visitor, $live)) !== null) {
                return $response;
            }
        }

        return null;
    }
}
