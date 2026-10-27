<?php

namespace App\Mcp\Tools\Domains;

use App\Actions\Domains\VerifyDomainOwnership;
use App\Enums\ApiAbility;
use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description(<<<'TEXT'
    Check a domain's ownership TXT record (from add-domain or check-domain) and, when it matches, mark
    the domain verified so the edge can serve it once DNS points there. Verifying doesn't send any
    visitors through the edge by itself.
    TEXT)]
#[IsIdempotent]
class VerifyDomain extends TeamTool
{
    protected ApiAbility $ability = ApiAbility::ManageDomains;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, VerifyDomainOwnership $verifyDomainOwnership): Response|ResponseFactory
    {
        $request->validate(['hostname' => ['required', 'string']]);

        $domain = DomainSummary::find($this->team(), $request->get('hostname'));

        if ($domain === null) {
            return Response::error(__('No domain called :hostname on this team.', ['hostname' => $request->get('hostname')]));
        }

        Gate::forUser($this->user())->authorize('update', $domain);

        $verifyDomainOwnership->handle($domain);

        return Response::structured(DomainSummary::of($domain->refresh()));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hostname' => $schema->string()->description('The domain to verify, like www.example.com.')->required(),
        ];
    }
}
