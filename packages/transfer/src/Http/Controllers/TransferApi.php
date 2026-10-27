<?php

namespace NiceTry\Transfer\Http\Controllers;

use App\Enums\ApiAbility;
use App\Http\Controllers\Controller;
use App\Mcp\ApiContext;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NiceTry\Origins\Models\Origin;
use NiceTry\Transfer\Actions\ExportOrigin;
use NiceTry\Transfer\OriginsAddon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What another panel pulls with, behind the API token check: the token's team's origins, and one of
 * them as a transfer bundle. A bundle holds the origin's secret, so the token must be allowed to add
 * and change domains, and its user still allowed to on the team. Anything the token may not see (no
 * such ability, Origins off, another team's origin, no origin at all) answers the same 404 an
 * unrouted path does.
 */
class TransferApi extends Controller
{
    public function __construct(private ApiContext $context)
    {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $token = $this->token($request);

        $origins = Origin::query()
            ->where('team_id', $token->team_id)
            ->withCount('domains')
            ->orderBy('name')
            ->get(['id', 'name', 'origin_url'])
            ->map(fn (Origin $origin) => [
                'id' => $origin->id,
                'name' => $origin->name,
                'origin_url' => $origin->origin_url,
                'sites' => (int) $origin->getAttribute('domains_count'),
            ])
            ->values();

        return $this->noStore(response()->json(['origins' => $origins]));
    }

    public function show(Request $request, ExportOrigin $export, int $origin): JsonResponse
    {
        $token = $this->token($request);
        $model = Origin::query()->where('team_id', $token->team_id)->find($origin) ?? $this->notFound($request);

        return $this->noStore(response()->json($export->handle($model), options: JSON_UNESCAPED_SLASHES));
    }

    /**
     * The request's token, when it may transfer origins.
     */
    private function token(Request $request): ApiToken
    {
        $token = $this->context->token();

        if (! OriginsAddon::isOn() || ! $token->canOnTeam(ApiAbility::ManageDomains)) {
            $this->notFound($request);
        }

        return $token;
    }

    /**
     * Word for word what the router says for a path it has no route for.
     */
    private function notFound(Request $request): never
    {
        throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }
}
