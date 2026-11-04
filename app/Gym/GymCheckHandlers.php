<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\GymCheckHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handlers packages have tagged into the edge's gym check. Without any, the check is the core's.
 */
class GymCheckHandlers
{
    /**
     * The container tag handlers register under.
     */
    public const string TAG = 'gym.check-handlers';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Get the first handler's answer to the check, or null when none answers.
     */
    public function answer(Request $request): ?Response
    {
        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $handler) {
            if ($handler instanceof GymCheckHandler && ($response = $handler->handle($request)) !== null) {
                return $response;
            }
        }

        return null;
    }
}
