<?php

namespace App\Mcp\Tools\Domains;

use App\Enums\ApiAbility;
use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description(<<<'TEXT'
    Take one of the team's domains out of NiceTryBot for good, as Remove does on its page: the edge stops
    serving it, so visitors whose DNS still points at the edge get an error until DNS points back at the
    origin. Point DNS away first. Its traffic history goes with it. To stop protecting it without
    removing it, call set-protection-mode with off. Confirm with the person first.
    TEXT)]
#[IsDestructive]
class RemoveDomain extends TeamTool
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

        Gate::forUser($this->user())->authorize('delete', $domain);

        $removed = ['hostname' => $domain->hostname, 'origin_url' => $domain->origin_url, 'dns_pointed_at_edge' => $domain->edge_dns_pointed_at !== null];

        $domain->delete();

        return Response::structured(['removed' => $removed]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hostname' => $schema->string()->description('The domain to remove, like www.example.com.')->required(),
        ];
    }
}
