<?php

namespace App\Data;

use App\Enums\HostingProvider;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Models\Domain;
use Illuminate\Support\Collection;

/**
 * What the edge needs beside single-request rules (EdgeExpression) to judge them: the path categories
 * in the order they're tried, the hosting networks' ASNs, and each domain's other hostnames, by the
 * hostname rules call it. Sent with every list that carries rule expressions.
 */
final class EdgeRuleEnvironment
{
    /**
     * The environment for the given domains (loaded with their edge hostnames).
     *
     * @param  Collection<int, Domain>  $domains
     * @return array{categories: list<array{id: int, patterns: list<string>}>, networks: list<array{kind: int, asns: array<int, int>}>, domains: array<int, array{hostname: string, aliases: list<string>}>}
     */
    public static function for(Collection $domains): array
    {
        return [
            'categories' => array_map(fn (PathCategory $category) => ['id' => $category->value, 'patterns' => $category->patterns()], PathCategory::cases()),
            'networks' => [['kind' => NetworkKind::Hosting->value, 'asns' => collect(HostingProvider::cases())->flatMap(fn (HostingProvider $provider) => $provider->asns())->unique()->sort()->values()->all()]],
            'domains' => $domains->map(fn (Domain $domain) => ['hostname' => $domain->hostname, 'aliases' => $domain->edgeHostnames()])->values()->all(),
        ];
    }
}
