<?php

namespace App\Mcp;

use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use RuntimeException;

/**
 * The token the current MCP request came in with: who it acts as, and on which team. One per request,
 * so a long-lived Octane worker never carries a token from one request into the next.
 */
#[Scoped]
class ApiContext
{
    private ?ApiToken $token = null;

    /**
     * Use a token for the rest of the request.
     */
    public function use(ApiToken $token): void
    {
        $this->token = $token;
    }

    /**
     * The token, which every tool can count on: the routes answer 404 without one.
     */
    public function token(): ApiToken
    {
        return $this->token ?? throw new RuntimeException('No API token for this request.');
    }

    /**
     * The token's user.
     */
    public function user(): User
    {
        return $this->token()->user;
    }

    /**
     * The token's team.
     */
    public function team(): Team
    {
        return $this->token()->team;
    }
}
