<?php

namespace App\Mcp;

use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The token the current MCP request came in with: who it acts as, and on which team. One per request,
 * so a long-lived Octane worker never carries a token from one request into the next.
 *
 * A token for one team always works on that team. A token for all of its user's teams works on the
 * team each call names in its "team" argument (a slug).
 */
#[Scoped]
class ApiContext
{
    private ?ApiToken $token = null;

    /**
     * The teams already looked up for this request, by the slug asked for.
     *
     * @var array<string, Team>
     */
    private array $teams = [];

    /**
     * Use a token for the rest of the request.
     */
    public function use(ApiToken $token): void
    {
        $this->token = $token;
        $this->teams = [];
    }

    /**
     * Determine if the request has a token yet.
     */
    public function hasToken(): bool
    {
        return $this->token !== null;
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
     * The team this call works on: the token's own, or, for a token for all teams, the one it names.
     *
     * @throws ValidationException when it names no team, or one the token doesn't reach.
     */
    public function team(): Team
    {
        $token = $this->token();
        $slug = $this->requestedTeam();

        if (! $token->coversAllTeams()) {
            if ($slug !== null && $slug !== $token->team->slug) {
                throw ValidationException::withMessages(['team' => __('This token only works on :team (:slug).', ['team' => $token->team->name, 'slug' => $token->team->slug])]);
            }

            return $token->team;
        }

        if ($slug === null) {
            throw ValidationException::withMessages(['team' => __('This token works on all of your teams: say which one with team, one of :slugs.', ['slugs' => $this->slugs()])]);
        }

        return $this->teams[$slug] ??= $token->user->teams()->where('slug', $slug)->first()
            ?? throw ValidationException::withMessages(['team' => __('No team called :slug. Teams: :slugs.', ['slug' => $slug, 'slugs' => $this->slugs()])]);
    }

    /**
     * The team the call named, if it named one.
     */
    public function requestedTeam(): ?string
    {
        $team = app()->bound('mcp.request') ? app('mcp.request')->get('team') : request()->input('team');

        return is_string($team) && trim($team) !== '' ? trim($team) : null;
    }

    /**
     * The slugs of the teams the token reaches, for an error to list.
     */
    private function slugs(): string
    {
        return $this->token()->teams()->pluck('slug')->implode(', ');
    }
}
