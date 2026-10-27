<?php

use App\Models\Domain;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge hears which domains choose their own Server header', function () {
    Domain::factory()->verified()->create(['hostname' => 'honeypot.example', 'server_header' => 'Apache/2.4.29 (Ubuntu)']);
    Domain::factory()->verified()->create(['hostname' => 'plain.example']);
    Domain::factory()->create(['hostname' => 'unverified.example', 'server_header' => 'Microsoft-IIS/8.5']);

    $this->getJson(route('edge.site-headers'))->assertUnauthorized();

    $this->withToken('edge-token')
        ->getJson(route('edge.site-headers'))
        ->assertExactJson(['sites' => [
            ['host' => 'honeypot-example.preview.example', 'server' => 'Apache/2.4.29 (Ubuntu)'],
            ['host' => 'honeypot.example', 'server' => 'Apache/2.4.29 (Ubuntu)'],
        ]]);
});

test('a domain\'s Server header is saved, blank means the default, and it stays on one line', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::domains.show', ['domain' => $domain])
        ->set('serverHeader', "Apache\r\nX-Injected: 1")
        ->call('saveOriginSettings')
        ->assertHasErrors(['serverHeader' => 'Use plain letters, numbers and punctuation, on one line.']);

    $component->set('serverHeader', ' Microsoft-IIS/8.5 ')->call('saveOriginSettings')->assertHasNoErrors();

    expect($domain->fresh()->server_header)->toBe('Microsoft-IIS/8.5');

    $component->set('serverHeader', '')->call('saveOriginSettings')->assertHasNoErrors();

    expect($domain->fresh()->server_header)->toBeNull();
});
