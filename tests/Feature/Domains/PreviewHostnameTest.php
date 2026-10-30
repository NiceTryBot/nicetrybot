<?php

use App\Models\Domain;
use App\Models\User;
use App\Support\PreviewHostname;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Http::preventStrayRequests();
});

test('every new domain gets its hostname as a preview under the preview zone', function () {
    $domain = Domain::factory()->create(['hostname' => 'bids.auctionhouse.example']);

    expect($domain->preview_hostname)->toBe('bids-auctionhouse-example.preview.example');
});

test('a preview is always one valid label, and never another domain\'s name', function () {
    Domain::factory()->create(['hostname' => 'shop.example.com']);
    $lookalike = Domain::factory()->create(['hostname' => 'shop-example.com']);
    $long = Domain::factory()->create(['hostname' => str_repeat('a', 40).'.'.str_repeat('b', 40).'.com']);
    $punycode = Domain::factory()->create(['hostname' => 'xn--bcher-kva.example']);

    expect($lookalike->preview_hostname)->toMatch('/^shop-example-com-[0-9a-f]{6}\.preview\.example$/')
        ->and(Str::before($long->preview_hostname, '.'))->toHaveLength(63)
        ->and($punycode->preview_hostname)->toBe('p-xn--bcher-kva-example.preview.example');
});

test('a site already under the preview zone has no preview of its own', function () {
    $domain = Domain::factory()->create(['hostname' => 'happy-ocean.preview.example']);

    expect($domain->preview_hostname)->toBeNull()
        ->and($domain->edgeHostnames())->toBe(['happy-ocean.preview.example']);
});

test('the edge serves a verified domain\'s preview as the domain itself', function () {
    $domain = Domain::factory()->verified()->create([
        'hostname' => 'claims.example.com',
        'origin_url' => 'https://app.example.net',
        'forwarded_host_header' => 'X-Domain',
    ]);

    askForCertificate('claims-example-com.preview.example')->assertOk();

    $this->withToken('edge-token')
        ->getJson(route('edge.origins.resolve', ['host' => 'claims-example-com.preview.example']))
        ->assertOk()
        ->assertJson([
            'host' => 'claims-example-com.preview.example',
            'site_host' => 'claims.example.com',
            'upstream' => 'app.example.net:443',
            'host_header' => 'claims.example.com',
            'forwarded_host_header' => 'X-Domain',
            'secret' => $domain->origin_secret,
        ]);
});

test('an unverified domain\'s preview gets no certificate and no route', function () {
    Domain::factory()->create(['hostname' => 'pending.example.com']);

    askForCertificate('pending-example-com.preview.example')->assertNotFound();

    $this->withToken('edge-token')
        ->getJson(route('edge.origins.resolve', ['host' => 'pending-example-com.preview.example']))
        ->assertNotFound();
});

test('the domain page offers the preview link, to open once ownership is verified', function () {
    $user = User::factory()->create();
    $pending = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'pending.example.com']);
    $verified = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'claims.example.com']);

    $this->actingAs($user);

    $this->get(route('domains.show', $pending))
        ->assertOk()
        ->assertSee('https://pending-example-com.preview.example')
        ->assertDontSee('data-test="open-preview"', false);

    $this->get(route('domains.show', $verified))
        ->assertOk()
        ->assertSee('href="https://claims-example-com.preview.example"', false)
        ->assertSee('data-test="open-preview"', false);
});

test('previews live under the configured zone', function () {
    config(['edge.preview_zone' => 'previews.example.net']);

    $domain = Domain::factory()->create(['hostname' => 'shop.example.com']);

    expect($domain->preview_hostname)->toBe('shop-example-com.previews.example.net')
        ->and(PreviewHostname::isPreview('happy-ocean.previews.example.net'))->toBeTrue()
        ->and(PreviewHostname::isPreview('happy-ocean.preview.example'))->toBeFalse();
});

test('with no preview zone, domains get no preview and the page offers none', function () {
    config(['edge.preview_zone' => null]);
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'claims.example.com']);

    expect($domain->preview_hostname)->toBeNull()
        ->and($domain->edgeHostnames())->toBe(['claims.example.com'])
        ->and(PreviewHostname::enabled())->toBeFalse()
        ->and(PreviewHostname::derive('claims.example.com'))->toBeNull()
        ->and(PreviewHostname::isPreview('claims-example-com.preview.example'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertOk()
        ->assertDontSee('data-test="preview-step"', false)
        ->assertSee('Point DNS at the edge');
});

test('a preview made before previews were turned off is hidden on the domain page', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'claims.example.com']);

    config(['edge.preview_zone' => null]);

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertOk()
        ->assertDontSee('claims-example-com.preview.example')
        ->assertDontSee('data-test="preview-step"', false);
});
