<?php

namespace NiceTry\Lockdown\Mcp;

use App\Enums\ApiAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
    Show the team's lockdown: whether it's engaged (the edge serving a plain Scheduled Maintenance page on
    every locked site, so nothing reaches their origins) and since when, how many sites are locked and which
    (the first 100 hostnames), the origins whose sites are all locked, the sites released from that, the IP
    addresses and ranges that still get through, whether the edges have picked up the latest change, and
    the Lockdown page's URL. Takes no input. Call it before and after lock-down-domains, release-domains or
    lift-lockdown to see where things stand.
    TEXT)]
class GetLockdownStatus extends LockdownTool
{
    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured($this->summary($this->lockdown('view')));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
