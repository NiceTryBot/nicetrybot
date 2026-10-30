<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Mcp\ApiContext;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Who this API token acts as: the team it works on, its user, what it was allowed to do, and when it
    expires. Call it first to check the token works and which team you're on. A token for all of its
    user's teams lists them: pass one's slug as team to every other tool.
    TEXT)]
#[IsReadOnly]
class Whoami extends TeamTool
{
    protected bool $needsTeam = false;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ApiContext $context): ResponseFactory
    {
        $token = $context->token();
        $team = $token->coversAllTeams() && $context->requestedTeam() === null ? null : $this->team();

        return Response::structured([
            'team' => $team !== null ? ['name' => $team->name, 'slug' => $team->slug] : null,
            ...($token->coversAllTeams() ? [
                'all_teams' => true,
                'teams' => $token->teams()->map(fn (Team $team) => ['name' => $team->name, 'slug' => $team->slug])->values()->all(),
            ] : []),
            'user' => $this->user()->email,
            'token' => $token->name,
            'abilities' => array_values(array_filter(
                array_map(fn (ApiAbility $ability) => $token->canOnTeam($ability, $team) ? $ability->value : null, ApiAbility::cases()),
            )),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);
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
