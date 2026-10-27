<?php

use App\Data\EdgeDnsCheck;
use App\Models\Domain;
use App\Models\User;

/**
 * The environment variables config/edge.php reads, all unset.
 *
 * @return array<string, null>
 */
function unsetEdgeEnv(): array
{
    return array_fill_keys(['NICETRY_EDGE_HOSTNAME', 'NICETRY_EDGE_ADDRESSES', 'NICETRY_PREVIEW_ZONE', 'NICETRY_PANEL_HOST', 'NICETRY_EDGE_LABEL', 'APP_URL'], null);
}

test('with nothing set the edge is the panel\'s own host, with no addresses, previews or label', function () {
    expect(configFileWith('edge.php', unsetEdgeEnv()))->toBe([
        'hostname' => '',
        'addresses' => [],
        'label' => null,
        'preview_zone' => null,
        'panel_host' => null,
    ]);

    expect(configFileWith('edge.php', [...unsetEdgeEnv(), 'APP_URL' => 'https://Panel.Example.org']))
        ->hostname->toBe('panel.example.org');

    expect(configFileWith('edge.php', [...unsetEdgeEnv(), 'APP_URL' => 'https://panel.example.org', 'NICETRY_PANEL_HOST' => 'bots.example.org']))
        ->hostname->toBe('bots.example.org');
});

test('a self-hosted stack sets its own DNS targets, and an empty preview zone turns previews off', function () {
    $config = configFileWith('edge.php', [
        'NICETRY_EDGE_HOSTNAME' => 'panel.example.com',
        'NICETRY_EDGE_ADDRESSES' => ' 192.0.2.10, ,192.0.2.11 ',
        'NICETRY_PREVIEW_ZONE' => '',
        'NICETRY_PANEL_HOST' => 'Panel.Example.com',
        'NICETRY_EDGE_LABEL' => ' us-east ',
    ]);

    expect($config)->toBe([
        'hostname' => 'panel.example.com',
        'addresses' => ['192.0.2.10', '192.0.2.11'],
        'label' => 'us-east',
        'preview_zone' => null,
        'panel_host' => 'panel.example.com',
    ]);

    expect(configFileWith('edge.php', ['NICETRY_EDGE_ADDRESSES' => '', 'NICETRY_PREVIEW_ZONE' => '.Previews.Example.NET.']))
        ->addresses->toBe([])
        ->preview_zone->toBe('previews.example.net');
});

test('the domain page tells people to point DNS at the configured edge', function () {
    config(['edge.hostname' => 'edge.example.net', 'edge.addresses' => ['192.0.2.10', '192.0.2.11']]);
    $user = User::factory()->create();
    $apex = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'example.org']);
    $subdomain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.org']);

    $this->actingAs($user);

    $this->get(route('domains.show', $apex))
        ->assertOk()
        ->assertSeeInOrder(['192.0.2.10', '192.0.2.11'])
        ->assertDontSee('203.0.113.10');

    $this->get(route('domains.show', $subdomain))
        ->assertOk()
        ->assertSee('edge.example.net');
});

test('a domain that resolves to a configured address points at the edge', function () {
    config(['edge.addresses' => ['192.0.2.10', '192.0.2.11']]);

    expect(new EdgeDnsCheck('example.org', ['192.0.2.11', '192.0.2.10'], []))->isPointedAtEdge()->toBeTrue()
        ->and(new EdgeDnsCheck('example.org', ['203.0.113.99'], []))->isPointedAtEdge()->toBeFalse();

    config(['edge.addresses' => []]);

    expect(new EdgeDnsCheck('example.org', ['192.0.2.10'], []))->isPointedAtEdge()->toBeFalse();
});
