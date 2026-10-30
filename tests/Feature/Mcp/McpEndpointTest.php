<?php

use App\Enums\ApiAbility;
use App\Mcp\Tools\Domains\AddDomain;
use App\Mcp\Tools\Domains\ListDomains;
use App\Models\ApiToken;
use App\Models\Team;
use Illuminate\Testing\TestResponse;

/**
 * An MCP initialize message, as an agent sends it first.
 *
 * @return array<string, mixed>
 */
function mcpInitialize(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
}

/**
 * Send a request to the MCP path and to a path that was never routed, the same way.
 *
 * @param  array<string, string>  $headers
 * @return array{0: TestResponse, 1: TestResponse}
 */
function mcpBesideMadeUpPath(string $method, array $headers = []): array
{
    $server = collect(['HTTP_ACCEPT' => 'application/json, text/event-stream', ...$headers])
        ->mapWithKeys(fn (string $value, string $key) => [str_starts_with($key, 'HTTP_') ? $key : 'HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])
        ->all();

    $body = json_encode(mcpInitialize());

    return [
        test()->call($method, '/mcp', [], [], [], $server, $body),
        test()->call($method, '/no-such-path', [], [], [], $server, $body),
    ];
}

/**
 * Assert two responses can't be told apart, apart from the path the not-found message names and how long they took.
 * (A HEAD response's body never leaves the server, so only its headers count.)
 */
function assertIndistinguishable(TestResponse $api, TestResponse $madeUp, string $method = 'POST'): void
{
    expect($api->getStatusCode())->toBe(404)
        ->and($madeUp->getStatusCode())->toBe(404);

    if ($method !== 'HEAD') {
        expect($api->getContent())->toBe(str_replace('no-such-path', 'mcp', $madeUp->getContent()));
    }

    $headers = fn (TestResponse $response) => collect($response->headers->all())
        ->except(['date'])
        ->map(fn (array $values, string $name) => $name === 'server-timing' ? preg_replace('/app;dur=[\d.]+/', '', $values) : $values)
        ->all();

    expect($headers($api))->toBe($headers($madeUp))
        ->and($api->headers->getCookies())->toBeEmpty();
}

beforeEach(function () {
    config(['app.debug' => false]);
});

test('without a token, every method on the MCP path answers exactly as a path that was never routed', function (string $method, array $headers) {
    [$api, $madeUp] = mcpBesideMadeUpPath($method, $headers);

    assertIndistinguishable($api, $madeUp, $method);
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'])->with([
    'browser' => [['Accept' => 'text/html']],
    'agent' => [['Accept' => 'application/json, text/event-stream']],
]);

test('a token that doesn\'t work answers the same 404, without the lookup showing in Server-Timing', function (Closure $makeToken) {
    $plainText = $makeToken();

    [$api, $madeUp] = mcpBesideMadeUpPath('POST', ['Authorization' => 'Bearer '.$plainText]);

    assertIndistinguishable($api, $madeUp);
    expect($api->headers->get('Server-Timing'))->toContain('desc="0 queries"');
})->with([
    'unknown' => fn () => ApiToken::PREFIX.str_repeat('a', ApiToken::SECRET_LENGTH),
    'not ours' => fn () => 'sk-ant-something-else',
    'expired' => function () {
        [$token, $plainText] = apiToken();
        $token->update(['expires_at' => now()->subMinute()]);

        return $plainText;
    },
    'user no longer an administrator' => function () {
        [$token, $plainText] = apiToken();
        $token->user->administrator->delete();

        return $plainText;
    },
    'user left the team' => function () {
        [$token, $plainText] = apiToken();
        $team = Team::factory()->create();
        $team->members()->attach($token->user, ['role' => 'admin']);
        $token->update(['team_id' => $team->id]);
        $team->members()->detach($token->user);

        return $plainText;
    },
    'team deleted' => function () {
        [$token, $plainText] = apiToken();
        $team = Team::factory()->create();
        $token->update(['team_id' => $team->id]);
        $team->delete();

        return $plainText;
    },
]);

test('a working token reaches the server, which acts as its user', function () {
    [$token, $plainText] = apiToken();

    $this->withToken($plainText)
        ->postJson('/mcp', mcpInitialize(), ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'NiceTryBot');

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

test('a token only lists the tools its abilities allow', function () {
    [$readOnly] = apiToken();
    [$everything] = apiToken(ApiAbility::cases());

    mcpAs($readOnly)->tools()->assertRegistered(ListDomains::class)->assertNotRegistered(AddDomain::class);
    mcpAs($everything)->tools()->assertRegistered([ListDomains::class, AddDomain::class]);
});

test('every tool a token may use comes back on the first page of the list', function () {
    [, $plainText] = apiToken(ApiAbility::cases());
    $headers = ['Accept' => 'application/json, text/event-stream'];

    $this->withToken($plainText)->postJson('/mcp', mcpInitialize(), $headers)->assertOk();

    $this->withToken($plainText)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)
        ->assertOk()
        ->assertJsonMissingPath('result.nextCursor')
        ->assertJsonFragment(['name' => 'lift-lockdown'])
        ->assertJsonFragment(['name' => 'add-domain']);
});

test('a tool call needs no initialize first, as the nicetrybot command sends one', function () {
    [, $plainText] = apiToken();

    $this->withToken($plainText)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'whoami',
                'arguments' => (object) [],
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'whoami',
        ])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.abilities', ['read']);
});
