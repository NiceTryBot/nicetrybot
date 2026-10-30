<?php

namespace App\Mcp\Tools;

use App\Enums\ApiAbility;
use App\Mcp\ApiContext;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that works on the API token's team. It's only listed, and only callable, when the token was
 * given its ability and the token's user has the team permission that goes with it.
 *
 * A token for all of its user's teams sees the tool when the user has that permission on any of them,
 * and each call names its team in a "team" argument, which every tool's schema then includes. The call
 * is checked again on the team it names.
 */
abstract class TeamTool extends Tool
{
    /**
     * What the token must be allowed to do to see this tool.
     */
    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Whether a token for all teams must name one (whoami, for one, works without).
     */
    protected bool $needsTeam = true;

    /**
     * Determine if the tool is offered to this token.
     */
    public function shouldRegister(ApiContext $context): bool
    {
        return $context->token()->canOnTeam($this->ability);
    }

    /**
     * The team this call works on, once the token may use this tool there.
     *
     * @throws AuthorizationException
     */
    protected function team(): Team
    {
        $context = app(ApiContext::class);
        $team = $context->team();

        if (! $context->token()->canOnTeam($this->ability, $team)) {
            throw new AuthorizationException(__('This token can\'t use :tool on :team: you don\'t have the permission there.', ['tool' => $this->name(), 'team' => $team->name]));
        }

        return $team;
    }

    /**
     * The token's user.
     */
    protected function user(): User
    {
        return app(ApiContext::class)->user();
    }

    /**
     * Get the tool's array representation, with the "team" argument for a token for all teams.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $context = app(ApiContext::class);

        if (! $context->hasToken() || ! $context->token()->coversAllTeams()) {
            return $tool;
        }

        $properties = (array) ($tool['inputSchema']['properties'] ?? []);
        $properties['team'] = [
            'type' => 'string',
            'description' => $this->needsTeam
                ? 'The team to work on, by its slug. This token works on several teams: whoami lists them.'
                : 'Optional: a team, by its slug, to see what the token may do there. Leave it out to list the teams.',
        ];
        $tool['inputSchema']['properties'] = $properties;

        if ($this->needsTeam) {
            $tool['inputSchema']['required'] = [...($tool['inputSchema']['required'] ?? []), 'team'];
        }

        return $tool;
    }
}
