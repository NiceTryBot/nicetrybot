<?php

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Mcp\Tools\Domains\AddDomain;
use App\Mcp\Tools\Domains\ListDomains;
use App\Mcp\Tools\Whoami;
use App\Models\Administrator;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * An administrator on their own team (acme) and as a plain member of another (globex), with a token
 * for all of their teams.
 *
 * @param  list<ApiAbility>  $abilities
 * @return array{0: ApiToken, 1: string, 2: Team, 3: Team}
 */
function allTeamsToken(array $abilities = []): array
{
    $user = User::factory()->create();
    Administrator::factory()->for($user)->create();
    $own = tap($user->currentTeam)->update(['name' => 'Acme', 'slug' => 'acme']);
    $other = Team::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
    $other->members()->attach($user, ['role' => TeamRole::Member->value]);

    [$token, $plainText] = ApiToken::issue($user->fresh(), null, 'Everywhere', $abilities);

    return [$token, $plainText, $own, $other];
}

/**
 * Call a tool over HTTP the way the nicetrybot command does: one stateless request.
 *
 * @param  array<string, mixed>  $arguments
 */
function callOverHttp(string $plainText, string $tool, array $arguments = []): TestResponse
{
    return test()->withToken($plainText)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $tool,
            'arguments' => (object) $arguments,
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => $tool,
    ]);
}

test('an administrator makes a token for all of their teams', function () {
    $admin = Administrator::factory()->create()->user;
    $this->actingAs($admin);

    Livewire::test('pages::admin.tokens')
        ->set('name', 'Everywhere')
        ->set('teamId', 'all')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('All teams');

    expect(ApiToken::sole()->coversAllTeams())->toBeTrue();
});

test('a token for all teams works on the team each call names, over HTTP', function () {
    [, $plainText, $own, $other] = allTeamsToken();
    Domain::factory()->for($own)->create(['hostname' => 'www.acme.test']);
    Domain::factory()->for($other)->create(['hostname' => 'www.globex.test']);

    callOverHttp($plainText, 'list-domains', ['team' => 'globex'])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.domains.0.hostname', 'www.globex.test')
        ->assertJsonCount(1, 'result.structuredContent.domains');

    callOverHttp($plainText, 'list-domains', ['team' => 'acme'])
        ->assertJsonPath('result.structuredContent.domains.0.hostname', 'www.acme.test');
});

test('a token for all teams must name a team it reaches, and is told which', function () {
    [, $plainText] = allTeamsToken();
    Team::factory()->create(['slug' => 'initech']);

    callOverHttp($plainText, 'list-domains')
        ->assertJsonPath('result.isError', true)
        ->assertSee('say which one with team, one of acme, globex');

    callOverHttp($plainText, 'list-domains', ['team' => 'initech'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('No team called initech. Teams: acme, globex.');
});

test('a token for all teams can only do on each team what its user may there', function () {
    [$token, , $own, $other] = allTeamsToken([ApiAbility::ManageDomains]);

    mcpAs($token)->tools()->assertRegistered(AddDomain::class);

    mcpAs($token)->tool(AddDomain::class, ['team' => 'acme', 'hostname' => 'www.acme.test', 'origin_url' => 'https://origin.acme.test'])
        ->assertOk();
    mcpAs($token)->tool(AddDomain::class, ['team' => 'globex', 'hostname' => 'www.globex.test', 'origin_url' => 'https://origin.globex.test'])
        ->assertHasErrors(['can\'t use add-domain on Globex']);

    expect($own->domains()->pluck('hostname')->all())->toBe(['www.acme.test'])
        ->and($other->domains()->count())->toBe(0);
});

test('a token for one team refuses a call that names another', function () {
    [$token] = apiToken();
    Team::factory()->create(['slug' => 'globex'])->members()->attach($token->user, ['role' => TeamRole::Owner->value]);

    mcpAs($token)->tool(ListDomains::class, ['team' => 'globex'])->assertHasErrors(['This token only works on '.$token->team->name]);
    mcpAs($token)->tool(ListDomains::class, ['team' => $token->team->slug])->assertOk();
});

test('a token for all teams lists them in whoami, and every other tool asks for one', function () {
    [$token, $plainText] = allTeamsToken();

    mcpAs($token)->tool(Whoami::class)->assertOk()->assertStructuredContent(fn ($content) => $content
        ->where('team', null)
        ->where('all_teams', true)
        ->where('teams', [['name' => 'Acme', 'slug' => 'acme'], ['name' => 'Globex', 'slug' => 'globex']])
        ->etc());

    $tools = collect($this->withToken($plainText)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
        ->json('result.tools'))->keyBy('name');

    expect($tools['list-domains']['inputSchema']['required'])->toContain('team')
        ->and($tools['list-domains']['inputSchema']['properties'])->toHaveKey('team')
        ->and($tools['whoami']['inputSchema']['properties'])->toHaveKey('team')
        ->and($tools['whoami']['inputSchema']['required'] ?? [])->not->toContain('team');
});
