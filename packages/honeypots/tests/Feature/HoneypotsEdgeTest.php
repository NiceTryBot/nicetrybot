<?php

use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotTrap;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Cache::flush();
});

test('the edge hears each enabled trap with its hosts, its honeypot and its patterns', function () {
    $team = Team::factory()->create();
    $shop = Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'blog.example']);
    Domain::factory()->for($team)->create(['hostname' => 'pending.example']);
    Domain::factory()->verified()->create(['hostname' => 'someone-else.example']);
    $galah = Honeypot::factory()->for($team)->create(['origin' => 'https://203-0-113-7.sslip.io/ignored', 'timeout_seconds' => 20]);

    $teamWide = HoneypotTrap::factory()->for($galah)->create(['team_id' => $team->id, 'patterns' => ['api/*', '/admin/*', 're:\.(env|git)']]);
    $onShop = HoneypotTrap::factory()->for($galah)->create(['team_id' => $team->id, 'domain_id' => $shop->id, 'patterns' => ['*.php']]);
    HoneypotTrap::factory()->for($galah)->disabled()->create(['team_id' => $team->id]);

    $this->getJson(route('edge.honeypots'))->assertUnauthorized();

    $response = $this->withToken('edge-token')->getJson(route('edge.honeypots'))->assertOk();

    expect($response->json('honeypots'))->toBe([
        [
            'id' => $teamWide->id,
            'honeypot' => $galah->id,
            'hosts' => ['blog-example.preview.example', 'blog.example', 'shop-example.preview.example', 'shop.example'],
            'origin' => 'https://203-0-113-7.sslip.io',
            'secret' => $galah->secret,
            'timeout' => 20,
            'globs' => ['/api/*', '/admin/*'],
            'regexes' => ['\.(env|git)'],
        ],
        [
            'id' => $onShop->id,
            'honeypot' => $galah->id,
            'hosts' => ['shop-example.preview.example', 'shop.example'],
            'origin' => 'https://203-0-113-7.sslip.io',
            'secret' => $galah->secret,
            'timeout' => 20,
            'globs' => ['*.php'],
            'regexes' => [],
        ],
    ]);
});

test('a new secret or a changed trap reaches the edge on its next poll', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    $galah = Honeypot::factory()->for($team)->create();
    $trap = HoneypotTrap::factory()->for($galah)->create(['team_id' => $team->id, 'patterns' => ['api/*']]);
    $oldSecret = $galah->secret;

    $this->withToken('edge-token')->getJson(route('edge.honeypots'))->assertJsonPath('honeypots.0.secret', $oldSecret);

    $galah->rotateSecret();
    $trap->update(['patterns' => ['admin/*']]);

    $this->withToken('edge-token')->getJson(route('edge.honeypots'))
        ->assertJsonPath('honeypots.0.secret', $galah->secret)
        ->assertJsonPath('honeypots.0.globs', ['/admin/*']);

    expect($galah->secret)->not->toBe($oldSecret);
});

test('a trap pointed at another team\'s honeypot is never sent', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    $theirs = Honeypot::factory()->create();
    HoneypotTrap::factory()->for($theirs)->create(['team_id' => $team->id]);

    $this->withToken('edge-token')->getJson(route('edge.honeypots'))->assertExactJson(['honeypots' => []]);
});

test('the edge hears an empty list when the add-on is off, so every 404 stays the site\'s', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    HoneypotTrap::factory()->for(Honeypot::factory()->for($team))->create(['team_id' => $team->id]);

    AddonSwitch::factory()->create(['slug' => 'honeypots']);
    app(AddonSwitchboard::class)->forget();

    $this->withToken('edge-token')->getJson(route('edge.honeypots'))->assertOk()->assertExactJson(['honeypots' => []]);
});
