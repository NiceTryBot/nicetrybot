<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Mcp\ApiContext;
use App\Models\Team;
use App\Models\User;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that works on the API token's team. It's only listed, and only callable, when the token was
 * given its ability and the token's user has the team permission that goes with it.
 */
abstract class TeamTool extends Tool
{
    /**
     * What the token must be allowed to do to see this tool.
     */
    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Determine if the tool is offered to this token.
     */
    public function shouldRegister(ApiContext $context): bool
    {
        return $context->token()->canOnTeam($this->ability);
    }

    /**
     * The token's team.
     */
    protected function team(): Team
    {
        return app(ApiContext::class)->team();
    }

    /**
     * The token's user.
     */
    protected function user(): User
    {
        return app(ApiContext::class)->user();
    }
}
