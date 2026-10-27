<?php

namespace App\Mcp;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Server\Tool;

/**
 * The MCP tools add-ons have tagged in, leaving out those of add-ons an administrator turned off.
 */
class McpTools
{
    /**
     * The container tag tools register under.
     */
    public const string TAG = 'mcp.tools';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $tool) => $tool instanceof Tool,
        ));
    }
}
