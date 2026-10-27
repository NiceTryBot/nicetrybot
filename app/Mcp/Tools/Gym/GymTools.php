<?php

namespace App\Mcp\Tools\Gym;

use Laravel\Mcp\Server\Tool;

/**
 * The MCP server's gym tools, listed here so each area keeps its own list.
 */
final class GymTools
{
    /**
     * @var list<class-string<Tool>>
     */
    public const array TOOLS = [
        ListGyms::class,
        ListObstacleTypes::class,
        CreateGym::class,
        UpdateGym::class,
        AddObstacle::class,
        RemoveObstacle::class,
        DeleteGym::class,
    ];
}
