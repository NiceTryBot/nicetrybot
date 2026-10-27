<?php

namespace NiceTry\RulePacks;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * Every rule pack a team can install: those tagged under TAG, in tag order (this add-on's first).
 */
class RulePacks
{
    /**
     * The container tag packs register under.
     */
    public const string TAG = 'rule-packs';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every pack, keyed by its key. A key already taken keeps the pack that had it first.
     *
     * @return array<string, RulePack>
     */
    public function all(): array
    {
        $packs = [];

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $pack) {
            if ($pack instanceof RulePack) {
                $packs[$pack->key()] ??= $pack;
            }
        }

        return $packs;
    }

    /**
     * Find a pack by its key.
     */
    public function find(string $key): ?RulePack
    {
        return $this->all()[$key] ?? null;
    }
}
