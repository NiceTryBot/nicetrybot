<?php

namespace App\Mcp\Tools\Traffic;

use Laravel\Mcp\Server\Tool;

/**
 * The MCP server's traffic tools, listed here so each area keeps its own list.
 */
final class TrafficTools
{
    /**
     * @var list<class-string<Tool>>
     */
    public const array TOOLS = [
        DescribeTrafficFields::class,
        QueryTraffic::class,
    ];
}
