<?php

namespace App\Mcp\Tools\Domains;

use App\Actions\Domains\CheckEdgeDns;
use App\Actions\Domains\CheckOriginLock;
use App\Enums\ProtectionMode;
use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Check where one domain stands and what's left to do. Reports: ownership (the TXT record to add and
    whether it's verified), the DNS records that point visitors at the edge and whether public DNS
    already does, and whether the origin is locked to the edge. The origin check asks the origin for
    its home page twice, as the edge would: without the secret header (a locked origin refuses it with
    a 4xx) and with it (it should serve it). Run it after locking the origin and after changing DNS.
    TEXT)]
#[IsReadOnly]
class CheckDomain extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CheckEdgeDns $checkEdgeDns, CheckOriginLock $checkOriginLock): Response|ResponseFactory
    {
        $request->validate(['hostname' => ['required', 'string']]);

        $domain = DomainSummary::find($this->team(), $request->get('hostname'));

        if ($domain === null) {
            return Response::error(__('No domain called :hostname on this team. Call list-domains to see them, or add-domain to add it.', ['hostname' => $request->get('hostname')]));
        }

        $dns = $checkEdgeDns->handle($domain);
        $domain->update(['edge_dns_pointed_at' => $dns->isPointedAtEdge() ? ($domain->edge_dns_pointed_at ?? now()) : null]);
        $origin = $checkOriginLock->handle($domain);

        return Response::structured([
            ...DomainSummary::of($domain),
            'ownership' => [
                ...DomainSummary::ownershipRecord($domain),
                'verified' => $domain->verified_at !== null,
            ],
            'dns' => [
                'records' => DomainSummary::edgeRecords($domain),
                'pointed_at_edge' => $dns->isPointedAtEdge(),
                'summary' => $dns->summary(),
            ],
            'origin' => $origin->toArray(),
            'next_steps' => $this->nextSteps($domain, $dns->isPointedAtEdge(), $origin->isLocked()),
        ]);
    }

    /**
     * What to do next, in order.
     *
     * @return list<string>
     */
    private function nextSteps(Domain $domain, bool $pointedAtEdge, bool $originLocked): array
    {
        $steps = [];

        if ($domain->verified_at === null) {
            $steps[] = __('Add the ownership TXT record, then call verify-domain.');
        }

        if (! $originLocked) {
            $steps[] = __('Make the origin refuse requests without the :header header (get the secret from get-origin-secret), then check again.', ['header' => Domain::ORIGIN_SECRET_HEADER]);
        }

        if (! $pointedAtEdge) {
            $steps[] = __('Point DNS at the edge with the records listed, after the origin is locked and verified. Ask the person first: their visitors will go through the edge.');
        } elseif ($domain->protection_mode !== ProtectionMode::Enforce) {
            $steps[] = __('Watch traffic for a while, then ask the person whether to set the protection mode to enforce.');
        }

        return $steps;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hostname' => $schema->string()->description('The domain visitors type, like www.example.com.')->required(),
        ];
    }
}
