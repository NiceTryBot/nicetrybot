<?php

namespace App\Mcp\Tools\Domains;

use Laravel\Mcp\Server\Tool;

/**
 * The MCP server's domain tools, listed here so each area keeps its own list.
 */
final class DomainTools
{
    /**
     * @var list<class-string<Tool>>
     */
    public const array TOOLS = [
        ListDomains::class,
        CheckDomain::class,
        AddDomain::class,
        VerifyDomain::class,
        GetOriginSecret::class,
        SetProtectionMode::class,
    ];
}
