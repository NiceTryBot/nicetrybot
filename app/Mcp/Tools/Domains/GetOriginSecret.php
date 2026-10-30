<?php

namespace App\Mcp\Tools\Domains;

use App\Enums\ApiAbility;
use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Get the secret header the edge sends with every request to a domain's origin. The origin should
    refuse requests without it, so nobody reaches the app by going around the edge. Store it in the
    app's environment, never in git, and compare it in constant time (hash_equals in PHP,
    crypto.timingSafeEqual in Node).
    TEXT)]
#[IsReadOnly]
class GetOriginSecret extends TeamTool
{
    protected ApiAbility $ability = ApiAbility::ManageDomains;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['hostname' => ['required', 'string']]);

        $domain = DomainSummary::find($this->team(), $request->get('hostname'));

        if ($domain === null) {
            return Response::error(__('No domain called :hostname on this team.', ['hostname' => $request->get('hostname')]));
        }

        Gate::forUser($this->user())->authorize('update', $domain);

        return Response::structured([
            'hostname' => $domain->hostname,
            'origin_url' => $domain->origin_url,
            'header' => Domain::ORIGIN_SECRET_HEADER,
            'value' => $domain->origin_secret,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hostname' => $schema->string()->description('The domain, like www.example.com.')->required(),
        ];
    }
}
