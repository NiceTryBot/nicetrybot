<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('_test/proxied', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'root' => url('/'),
    ]);
});

/**
 * Ask the test route as the edge would: from a container address, with the visitor in X-Forwarded-*.
 */
function requestThroughProxy(string $from, array $headers = []): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $from])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            ...$headers,
        ])
        ->getJson('http://panel.example.com/_test/proxied')
        ->assertOk()
        ->json();
}

test('no proxy is trusted unless one is configured, as on Vapor', function () {
    config(['trustedproxy.proxies' => null]);

    expect(requestThroughProxy('172.18.0.5'))->toMatchArray(['ip' => '172.18.0.5', 'secure' => false]);
});

test('behind the self-hosted edge, the visitor and https come from its forwarded headers', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16']);

    expect(requestThroughProxy('172.18.0.5'))->toBe([
        'ip' => '203.0.113.7',
        'secure' => true,
        'root' => 'https://panel.example.com',
    ]);
});

test('an edge reaching the app over the network\'s own IPv6 is trusted too', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7']);

    expect(requestThroughProxy('fd27:2bea:9b65::6'))->toMatchArray(['ip' => '203.0.113.7', 'secure' => true]);
});

test('forwarded headers from outside the trusted ranges are ignored', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16']);

    expect(requestThroughProxy('198.51.100.4'))->toMatchArray(['ip' => '198.51.100.4', 'secure' => false]);
});

test('TRUSTED_PROXIES drives the setting, and empty trusts no one', function () {
    expect(configFileWith('trustedproxy.php', ['TRUSTED_PROXIES' => '172.16.0.0/12'])['proxies'])->toBe('172.16.0.0/12')
        ->and(configFileWith('trustedproxy.php', ['TRUSTED_PROXIES' => ''])['proxies'])->toBeNull()
        ->and(configFileWith('trustedproxy.php', ['TRUSTED_PROXIES' => null])['proxies'])->toBeNull();
});

test('nobody but the edge picks the host the app builds its links on, not even a trusted proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', 'services.nicetry_edge.token' => 'edge-secret']);

    expect(requestThroughProxy('172.18.0.5', ['X-Forwarded-Host' => 'evil.example'])['root'])->toBe('https://panel.example.com')
        ->and(requestThroughProxy('198.51.100.4', ['X-Forwarded-Host' => 'evil.example', 'Authorization' => 'Bearer wrong'])['root'])->toBe('http://panel.example.com')
        ->and(requestThroughProxy('172.18.0.5', ['X-Forwarded-Host' => 'shop.example.com', 'Authorization' => 'Bearer edge-secret'])['root'])->toBe('https://shop.example.com');
});

test('on Vapor the visitor\'s IP is the one its runtime saw, whatever X-Forwarded-For says', function () {
    config(['trustedproxy.proxies' => ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']]);
    putenv('LAMBDA_TASK_ROOT=/var/task');

    try {
        expect(requestThroughProxy('127.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.7', 'X-Vapor-Source-Ip' => '198.51.100.20'])['ip'])->toBe('198.51.100.20');
    } finally {
        putenv('LAMBDA_TASK_ROOT');
    }
});
