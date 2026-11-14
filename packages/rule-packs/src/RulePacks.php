<?php

namespace NiceTry\RulePacks;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * Every rule pack a team can install: those tagged under TAG, this add-on's first.
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
     * Every pack, keyed by its key: this add-on's in its own order, then other add-ons' in tag order,
     * whichever add-on the framework happened to load first. A key already taken keeps the pack that
     * had it first.
     *
     * @return array<string, RulePack>
     */
    public function all(): array
    {
        $packs = [];
        $tagged = app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG));
        $order = array_flip(RulePacksServiceProvider::PACKS);
        usort($tagged, fn (object $first, object $second) => ($order[$first::class] ?? PHP_INT_MAX) <=> ($order[$second::class] ?? PHP_INT_MAX));

        foreach ($tagged as $pack) {
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
