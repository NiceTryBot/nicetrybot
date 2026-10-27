<?php

use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Models\Domain;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge hears where each verified domain\'s traffic goes, under its own name and its preview\'s', function () {
    $passThrough = Domain::factory()->verified()->create([
        'hostname' => 'claims.example.com',
        'origin_url' => 'https://app.example.net',
    ]);
    $rewrite = Domain::factory()->paused()->create([
        'hostname' => 'preview.example.com',
        'origin_url' => 'http://sites.example.org:8080',
        'host_header_mode' => HostHeaderMode::Rewrite,
        'forwarded_host_header' => 'X-Domain, User-Custom-Domain, X-Site',
        'origin_timeout_seconds' => 10,
        'hsts_policy' => HstsPolicy::On,
        'cache_assets' => true,
    ]);
    Domain::factory()->create(['hostname' => 'unverified.example.com']);
    $claims = [
        'host' => 'claims.example.com',
        'site_host' => 'claims.example.com',
        'scheme' => 'https',
        'upstream' => 'app.example.net:443',
        'server_name' => 'app.example.net',
        'host_header' => 'claims.example.com',
        'forwarded_host_header' => null,
        'secret' => $passThrough->origin_secret,
        'timeout' => 30,
        'extra_forwarded_host_headers' => [],
        'hsts' => null,
        'cache_assets' => false,
    ];
    $rewritten = [
        'host' => 'preview.example.com',
        'site_host' => 'preview.example.com',
        'scheme' => 'http',
        'upstream' => 'sites.example.org:8080',
        'server_name' => 'sites.example.org',
        'host_header' => 'sites.example.org',
        'forwarded_host_header' => 'X-Domain',
        'secret' => $rewrite->origin_secret,
        'timeout' => 10,
        'extra_forwarded_host_headers' => ['User-Custom-Domain', 'X-Site'],
        'hsts' => 'max-age=31536000',
        'cache_assets' => true,
    ];

    $this->getJson(route('edge.origins'))->assertUnauthorized();

    $this->withToken('edge-token')
        ->getJson(route('edge.origins'))
        ->assertOk()
        ->assertExactJson(['origins' => [
            [...$claims, 'host' => 'claims-example-com.preview.example'],
            $claims,
            [...$rewritten, 'host' => 'preview-example-com.preview.example'],
            $rewritten,
        ]]);
});

test('the edge hears each HSTS policy as the header value to send', function (HstsPolicy $policy, ?string $hsts) {
    Domain::factory()->verified()->create(['hsts_policy' => $policy]);

    $this->withToken('edge-token')
        ->getJson(route('edge.origins'))
        ->assertOk()
        ->assertJsonPath('origins.0.hsts', $hsts);
})->with([
    'off' => [HstsPolicy::Off, null],
    'on' => [HstsPolicy::On, 'max-age=31536000'],
    'preload' => [HstsPolicy::Preload, 'max-age=31536000; includeSubDomains; preload'],
]);

test('an unchanged list costs the edge a 304', function () {
    Domain::factory()->verified()->create();

    $etag = $this->withToken('edge-token')->getJson(route('edge.origins'))->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeEmpty();

    $this->withToken('edge-token')
        ->withHeader('If-None-Match', $etag)
        ->getJson(route('edge.origins'))
        ->assertStatus(304);
});
