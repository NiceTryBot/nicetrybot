<?php

namespace App\Mcp\Tools\Rules;

use Laravel\Mcp\Server\Tool;

/**
 * The MCP server's rule tools, listed here so each area keeps its own list.
 */
final class RuleTools
{
    /**
     * @var list<class-string<Tool>>
     */
    public const array TOOLS = [
        ListRules::class,
        DescribeRuleLanguage::class,
        BacktestRule::class,
        CreateRule::class,
        UpdateRule::class,
        DeleteRule::class,
    ];
}
