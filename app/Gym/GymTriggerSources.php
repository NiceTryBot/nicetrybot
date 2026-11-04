<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Gym\Contracts\GymTriggerSource;
use Illuminate\Contracts\Container\Container;

/**
 * The cookie triggers packages have tagged in, for the edge's gym trigger list.
 */
class GymTriggerSources
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'gym.trigger-sources';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every source's cookie triggers, those without hosts left out.
     *
     * @return list<array{hosts: list<string>, path: string, rules?: list<array{id: int, expr: array<string, mixed>}>}>
     */
    public function cookieTriggers(): array
    {
        $triggers = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $source) {
            if ($source instanceof GymTriggerSource) {
                foreach ($source->cookieTriggers() as $trigger) {
                    if ($trigger['hosts'] !== []) {
                        $triggers[] = $trigger;
                    }
                }
            }
        }

        return $triggers;
    }
}
