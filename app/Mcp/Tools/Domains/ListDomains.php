<?php

namespace App\Mcp\Tools\Domains;

use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the team's domains: each hostname visitors type, the origin URL the edge forwards to, whether
    ownership is verified, whether DNS points at the edge, and the protection mode (off, observe,
    enforce). Call check-domain for one domain's DNS records and next steps.
    TEXT)]
#[IsReadOnly]
class ListDomains extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        // A site of a shared origin asks its origin whether it serves www., so load that up front.
        $domains = $this->team()->domains()->with('team')->withEdgeHostnames()->orderBy('hostname')->get();

        return Response::structured([
            'team' => $this->team()->name,
            'domains' => $domains->map(fn ($domain) => DomainSummary::of($domain))->all(),
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
