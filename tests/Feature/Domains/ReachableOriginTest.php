<?php

use App\Actions\Domains\LookUpDnsRecords;
use App\Enums\ApiAbility;
use App\Mcp\Tools\Domains\AddDomain;
use App\Models\Domain;
use App\Rules\ReachableOrigin;
use Illuminate\Support\Facades\Validator;

/**
 * The first error the rule gives an origin URL, or null when it passes.
 */
function originError(string $url, ?string $hostname = 'www.mine.test'): ?string
{
    return Validator::make(['origin' => $url], ['origin' => [new ReachableOrigin($hostname)]])->errors()->first('origin') ?: null;
}

/**
 * Resolve one name to these addresses, and every other name to nothing.
 *
 * @param  list<string>  $addresses
 */
function resolveTo(string $host, array $addresses): void
{
    test()->mock(LookUpDnsRecords::class, fn ($mock) => $mock->shouldReceive('handle')
        ->andReturnUsing(fn (string $name) => ['ipv4' => $name === $host ? $addresses : [], 'ipv6' => []]));
}

test('an origin on a private, loopback or link-local address is refused', function (string $url) {
    expect(originError($url))->toContain('a private or reserved address');
})->with([
    'cloud metadata' => 'http://169.254.169.254',
    'private network' => 'http://10.0.0.5:8080',
    'loopback' => 'https://127.0.0.1',
    'IPv6 loopback' => 'http://[::1]',
    'carrier-grade NAT' => 'http://100.64.1.1',
]);

test('a name that resolves to a private address is refused, and a public one passes', function () {
    resolveTo('origin.mine.test', ['10.1.2.3']);
    expect(originError('https://origin.mine.test'))->toContain('resolves to 10.1.2.3');

    resolveTo('origin.mine.test', ['93.184.215.14']);
    expect(originError('https://origin.mine.test'))->toBeNull();
});

test('an origin the edge serves itself is refused, so requests can\'t loop', function () {
    config(['edge.hostname' => 'edge.example.net', 'edge.addresses' => ['198.51.100.7'], 'edge.preview_zone' => 'preview.example']);
    Domain::factory()->create(['hostname' => 'other.example.com']);
    resolveTo('sneaky.example.com', ['198.51.100.7']);

    expect(originError('https://www.mine.test'))->toContain('different host than the domain')
        ->and(originError('https://edge.example.net'))->toContain('not a site or preview this edge serves')
        ->and(originError('https://www-mine-test.preview.example'))->toContain('not a site or preview this edge serves')
        ->and(originError('https://other.example.com'))->toContain('not a site or preview this edge serves')
        ->and(originError('https://sneaky.example.com'))->toContain('resolves to the edge itself');
});

test('an edge meant for a private network may forward to one, but never to itself', function () {
    config(['nicetry.allow_private_origins' => true, 'edge.hostname' => 'edge.example.net']);

    expect(originError('http://10.0.0.5:8080'))->toBeNull()
        ->and(originError('https://edge.example.net'))->not->toBeNull();
});

test('add-domain refuses an origin on the cloud\'s metadata address', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);

    mcpAs($token)->tool(AddDomain::class, ['hostname' => 'www.mine.test', 'origin_url' => 'http://169.254.169.254', 'host_header_mode' => 'rewrite'])
        ->assertHasErrors(['a private or reserved address']);

    expect(Domain::count())->toBe(0);
});
