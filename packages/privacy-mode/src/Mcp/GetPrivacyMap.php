<?php

namespace NiceTry\PrivacyMode\Mcp;

use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use NiceTry\PrivacyMode\Models\PrivacyWord;
use NiceTry\PrivacyMode\Support\HostnameMap;

#[IsReadOnly]
#[Description(<<<'TEXT'
    Get what privacy mode swaps to redact the team's traffic for screen sharing: every real host (its
    domains, their previews and the hosts their origins live on) with its made-up stand-in, like
    invisible-pony.hidden-domain.com, and every word in Settings → Privacy with what shows in its place.
    Hostnames match whole, words anywhere in any case. Takes no input. Use it before showing traffic to
    an audience, and mask IPs to their first two octets (193.148.x.x) as well.
    TEXT)]
class GetPrivacyMap extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'hostnames' => (object) HostnameMap::mapFor($this->team()),
            'words' => (object) PrivacyWord::map(),
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
