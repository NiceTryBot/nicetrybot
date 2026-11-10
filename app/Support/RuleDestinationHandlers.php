<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;

/**
 * The destination clauses rule scripts can name (App\Support\RuleDestinationHandler): the core's gym,
 * then the ones installed add-ons bring (tarpit, blackhole). A keyword is only known while its add-on is
 * on, so a script can't name a destination the team can't reach.
 */
#[Singleton]
class RuleDestinationHandlers
{
    /**
     * The container tag handlers register under.
     */
    public const string TAG = 'rules.destination-handlers';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every destination handler whose add-on is on.
     *
     * @return list<RuleDestinationHandler>
     */
    public function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $handler) => $handler instanceof RuleDestinationHandler,
        ));
    }

    /**
     * The handler for a keyword, ignoring case, or null when none is known (no such add-on is on).
     */
    public function keyword(string $keyword): ?RuleDestinationHandler
    {
        $keyword = strtoupper($keyword);

        foreach ($this->all() as $handler) {
            if ($handler->keyword() === $keyword) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Every known keyword, uppercase, for the parser and the palette.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return array_map(fn (RuleDestinationHandler $handler) => $handler->keyword(), $this->all());
    }
}
