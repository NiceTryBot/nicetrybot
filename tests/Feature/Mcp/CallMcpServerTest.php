<?php

use App\Models\Administrator;
use App\Models\Domain;
use App\Models\User;

/**
 * A tools/call message, as the nicetrybot command sends it.
 *
 * @param  array<string, mixed>  $arguments
 */
function toolCall(string $tool, array $arguments = []): string
{
    return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
}

test('it runs a tool on the first administrator\'s team as its owner, with every ability', function () {
    $administrator = Administrator::factory()->create()->user;

    $this->artisan('nicetry:mcp', ['message' => toolCall('add-domain', ['hostname' => 'docs.example.test', 'origin_url' => 'https://docs-origin.example.test', 'host_header_mode' => 'rewrite'])])
        ->expectsOutputToContain('"isError":false')
        ->assertSuccessful();

    expect(Domain::sole())
        ->team_id->toBe($administrator->current_team_id)
        ->host_header_mode->value->toBe('rewrite');
});

test('it works on the team it\'s given, by slug', function () {
    Administrator::factory()->create();
    $other = User::factory()->create();
    Domain::factory()->for($other->currentTeam)->create(['hostname' => 'www.other.test']);

    $this->artisan('nicetry:mcp', ['message' => toolCall('list-domains'), '--team' => $other->currentTeam->slug])
        ->expectsOutputToContain('www.other.test')
        ->assertSuccessful();
});

test('it refuses a team that doesn\'t exist', function () {
    Administrator::factory()->create();

    $this->artisan('nicetry:mcp', ['message' => toolCall('list-domains'), '--team' => 'nobody'])
        ->expectsOutputToContain('No team called nobody')
        ->assertFailed();
});
