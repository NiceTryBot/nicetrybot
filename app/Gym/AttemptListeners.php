<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\AttemptListener;
use Illuminate\Contracts\Container\Container;

/**
 * The listeners packages have tagged in to hear about visitors' attempts. Without any, an attempt is
 * exactly the core's.
 */
class AttemptListeners
{
    /**
     * The container tag listeners register under.
     */
    public const string TAG = 'gym.attempt-listeners';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Tell every listener about an attempt, in the order they were tagged.
     */
    public function notify(ObstacleAttempt $attempt): void
    {
        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $listener) {
            if ($listener instanceof AttemptListener) {
                $listener->attempted($attempt);
            }
        }
    }
}
