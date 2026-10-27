<?php

namespace App\Mcp\Servers;

use App\Mcp\McpTools;
use App\Mcp\Tools\Domains\DomainTools;
use App\Mcp\Tools\Gym\GymTools;
use App\Mcp\Tools\Rules\RuleTools;
use App\Mcp\Tools\Traffic\TrafficTools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The MCP server AI agents use to run this installation for one team: put domains behind the edge,
 * ask about traffic, write rules, train gyms and, with the add-on, lock sites down. Every request
 * carries an API token an administrator made in Admin; without one the route is a 404.
 */
#[Name('NiceTryBot')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    NiceTryBot sits in front of websites (the "edge") and tells real people from bots. You're working on
    one team's sites, the team the API token belongs to.

    - Domains are the hostnames visitors type. Each one forwards to an origin URL where the app really
      lives, like https://www-origin.example.com. A domain needs its ownership TXT record checked, then
      DNS pointed at the edge, before the edge serves it.
    - The edge sends every request to the origin with a secret header. The origin should refuse any
      request without it, so nobody can go around the edge.
    - Rules match visitors (country, network, browser, path, bot signals...) and decide what happens:
      allow, ban, or send them to a gym, a routine of obstacles real people pass and bots don't.
    - Countries are ISO 3166 codes: the Netherlands is NL (NE is Niger). Say which country you mean
      when a request could be either.
    - Before you change anything a visitor would notice (enforcing, banning, locking down), say what
      will happen and get a yes.
    MARKDOWN)]
class NiceTryBotServer extends Server
{
    /**
     * @var array<int, class-string<Server\Tool>|Server\Tool>
     */
    protected array $tools = [
        ...DomainTools::TOOLS,
        ...TrafficTools::TOOLS,
        ...RuleTools::TOOLS,
        ...GymTools::TOOLS,
    ];

    /**
     * List every tool on one page: some agents never ask for a second.
     */
    public int $defaultPaginationLength = 50;

    public int $maxPaginationLength = 100;

    /**
     * Add the tools add-ons contribute (those turned on).
     */
    protected function boot(): void
    {
        $this->tools = [...$this->tools, ...app(McpTools::class)->all()];
    }
}
