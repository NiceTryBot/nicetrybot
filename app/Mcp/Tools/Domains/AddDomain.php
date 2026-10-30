<?php

namespace App\Mcp\Tools\Domains;

use App\Actions\Domains\AddDomain as AddDomainAction;
use App\Enums\ApiAbility;
use App\Enums\HostHeaderMode;
use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use App\Rules\HeaderNames;
use App\Rules\Hostname;
use App\Rules\ReachableOrigin;
use App\Support\HeaderName;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
    Put a domain behind the NiceTryBot edge. Give the hostname visitors type (like www.example.com) and
    the origin URL where the app really lives (like https://www-origin.example.com): a different host,
    reachable from the internet, that serves the same app. Nothing changes for visitors yet: the domain
    starts unverified, in observe mode, until its TXT record is checked and DNS points at the edge.
    Returns the TXT record, the edge DNS records, the origin secret header the edge will send, and the
    next steps. Lock the origin to that header before pointing DNS at the edge.
    TEXT)]
class AddDomain extends TeamTool
{
    protected ApiAbility $ability = ApiAbility::ManageDomains;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, AddDomainAction $addDomain): ResponseFactory
    {
        Gate::forUser($this->user())->authorize('create', [Domain::class, $this->team()]);

        $hostname = Hostname::normalize((string) $request->get('hostname', ''));

        $request->merge([
            'hostname' => $hostname,
            'forwarded_host_header' => HeaderName::normalizeList((string) $request->get('forwarded_host_header', '')),
        ]);

        $validated = $request->validate([
            'hostname' => ['required', 'string', new Hostname, Rule::unique('domains', 'hostname')],
            'origin_url' => [
                'required',
                'string',
                'max:255',
                'url:http,https',
                new ReachableOrigin($hostname),
            ],
            'host_header_mode' => ['nullable', Rule::enum(HostHeaderMode::class)],
            'origin_host_header' => ['nullable', 'string', new Hostname],
            'forwarded_host_header' => ['nullable', 'string', new HeaderNames],
            'serves_www' => ['nullable', 'boolean'],
        ], [
            'hostname.unique' => __('That domain is already protected by NiceTryBot.'),
        ]);

        $domain = $addDomain->handle($this->team(), [
            'hostname' => $validated['hostname'],
            'origin_url' => $validated['origin_url'],
            'host_header_mode' => $validated['host_header_mode'] ?? HostHeaderMode::PassThrough->value,
            'origin_host_header' => $validated['origin_host_header'] ?? null,
            'forwarded_host_header' => $validated['forwarded_host_header'] ?? null,
            'serves_www' => $validated['serves_www'] ?? false,
        ]);

        return Response::structured([
            ...DomainSummary::of($domain),
            'ownership_record' => DomainSummary::ownershipRecord($domain),
            'edge_records' => DomainSummary::edgeRecords($domain),
            'origin_secret' => [
                'header' => Domain::ORIGIN_SECRET_HEADER,
                'value' => $domain->origin_secret,
                'note' => __('Keep it out of git: put it in the app\'s environment (for example NICETRY_ORIGIN_SECRET) and have the origin refuse requests without it. Compare it in constant time.'),
            ],
            'next_steps' => [
                __('Add the ownership TXT record, then call verify-domain.'),
                __('Make the origin refuse any request without the :header header carrying that secret (a 403 is fine).', ['header' => Domain::ORIGIN_SECRET_HEADER]),
                __('Call check-domain: the origin should refuse a request without the header and serve one with it.'),
                __('Only then, and with the person\'s go-ahead, point DNS at the edge with the edge records.'),
            ],
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
            'hostname' => $schema->string()->description('The hostname visitors type, without https:// or a path, like www.example.com.')->required(),
            'origin_url' => $schema->string()->description('Where the edge forwards visitors: the app on another host, like https://www-origin.example.com. Must not be the hostname itself.')->required(),
            'serves_www' => $schema->boolean()->description('Also serve www.<hostname> as the same site (only for a hostname that doesn\'t start with www.). Default false.'),
            'host_header_mode' => $schema->string()->enum(array_column(HostHeaderMode::cases(), 'value'))->description('pass_through (default): the origin receives the Host the visitor sent. rewrite: the origin receives its own host. Use rewrite for one origin serving many domains, and when the origin is on a platform that routes by Host (CloudFront, Laravel Vapor, Vercel, Netlify, Heroku, Render...), which refuses a Host it doesn\'t know. With rewrite, the app must build its links on the public hostname (APP_URL, or forwarded_host_header), not the Host it receives.'),
            'origin_host_header' => $schema->string()->description('With rewrite, the Host to send instead of the origin URL\'s own host. Usually leave empty.'),
            'forwarded_host_header' => $schema->string()->description('Optional header names (comma-separated) that carry the visitor\'s hostname to the origin, like X-Domain.'),
        ];
    }
}
