<?php

namespace App\Http\Middleware;

use App\Mcp\ApiContext;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Let a request through only with a working API token, and otherwise answer exactly as though the
 * route didn't exist: the same 404 a made-up path gets, with no cookie, no WWW-Authenticate and no
 * database time in Server-Timing, so a stranger can't tell there's an API here at all.
 *
 * A token works while it hasn't expired, its user is still an administrator, and they're still on
 * the token's team (a token for all of their teams reaches whichever they're on at the time).
 */
class AuthenticateApiToken
{
    public function __construct(private ApiContext $context)
    {
        //
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFor($request);

        if ($token === null) {
            AddServerTiming::forgetQueries();

            // Word for word what the router says for a path it has no route for.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        $token->touchLastUsed();
        $this->context->use($token);
        Auth::setUser($token->user);

        return $next($request);
    }

    /**
     * The working token the request carries, if it has one.
     */
    private function tokenFor(Request $request): ?ApiToken
    {
        $plainText = $request->bearerToken();

        if ($plainText === null || ! ApiToken::looksValid($plainText)) {
            return null;
        }

        $token = ApiToken::findByPlainText($plainText);

        if ($token === null || ! $token->user->isAdministrator()) {
            return null;
        }

        if (! $token->coversAllTeams() && ($token->team === null || ! $token->user->belongsToTeam($token->team))) {
            return null;
        }

        return $token;
    }
}
