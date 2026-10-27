<?php

namespace App\Mcp\Tools\Domains;

use App\Enums\ApiAbility;
use App\Enums\ProtectionMode;
use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description(<<<'TEXT'
    Set how the edge treats a domain's suspicious traffic: off (proxy and log only), observe (tag it
    but let it through; where every domain starts) or enforce (send it to the gym or block it). Enforce
    changes what visitors see, so get the person's go-ahead first.
    TEXT)]
#[IsDestructive]
class SetProtectionMode extends TeamTool
{
    protected ApiAbility $ability = ApiAbility::ManageDomains;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'hostname' => ['required', 'string'],
            'mode' => ['required', Rule::enum(ProtectionMode::class)],
        ]);

        $domain = DomainSummary::find($this->team(), $validated['hostname']);

        if ($domain === null) {
            return Response::error(__('No domain called :hostname on this team.', ['hostname' => $validated['hostname']]));
        }

        Gate::forUser($this->user())->authorize('update', $domain);

        $domain->setProtectionMode(ProtectionMode::from($validated['mode']));

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
            'hostname' => $schema->string()->description('The domain, like www.example.com.')->required(),
            'mode' => $schema->string()->enum(array_column(ProtectionMode::cases(), 'value'))->description('off, observe or enforce.')->required(),
        ];
    }
}
