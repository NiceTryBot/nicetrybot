<?php

use App\Support\AllowedAddresses;
use App\Support\AllowedAddressSource;

/**
 * A source we tag in by hand: not claimed by any add-on, so the switchboard treats it as the core's and
 * always on. The White List and Nice Bots tests cover a real add-on's contributor dropping out when off.
 */
class FakeAllowSource implements AllowedAddressSource
{
    /**
     * @var list<string>
     */
    public static array $ranges = [];

    public function allowedRanges(): array
    {
        return static::$ranges;
    }
}

beforeEach(function () {
    FakeAllowSource::$ranges = [];
    config(['services.nicetry_edge.token' => 'edge-token']);
});

function allow(array $ranges): AllowedAddresses
{
    FakeAllowSource::$ranges = $ranges;
    app()->tag([FakeAllowSource::class], AllowedAddresses::TAG);
    app()->forgetScopedInstances();

    return app(AllowedAddresses::class);
}

test('an address in a listed range is allowed, one outside it is not', function () {
    $allowed = allow(['66.249.64.0/27', '203.0.113.5']);

    expect($allowed->allows('66.249.64.10'))->toBeTrue()
        ->and($allowed->allows('203.0.113.5'))->toBeTrue()
        ->and($allowed->allows('66.249.64.200'))->toBeFalse()
        ->and($allowed->allows('8.8.8.8'))->toBeFalse();
});

test('an IPv6 address in a listed range is allowed', function () {
    $allowed = allow(['2001:4860:4801:10::/64']);

    expect($allowed->allows('2001:4860:4801:10::abcd'))->toBeTrue()
        ->and($allowed->allows('2001:4860:4801:11::1'))->toBeFalse();
});

test('a malformed address is never allowed, and an empty list allows nothing', function () {
    expect(allow(['66.249.64.0/27'])->allows('not-an-ip'))->toBeFalse()
        ->and(allow([])->allows('66.249.64.10'))->toBeFalse();
});

test('the gathered list is deduplicated', function () {
    expect(allow(['203.0.113.5', '203.0.113.5', '66.249.64.0/27'])->ranges())
        ->toBe(['203.0.113.5', '66.249.64.0/27']);
});

test('only the edge hears the allow feed', function () {
    allow(['66.249.64.0/27']);

    $this->getJson(route('edge.allowed'))->assertUnauthorized();
});

test('the edge is told every allowed range', function () {
    allow(['66.249.64.0/27', '203.0.113.5']);

    $this->withToken('edge-token')
        ->getJson(route('edge.allowed'))
        ->assertOk()
        ->assertExactJson(['allow' => ['66.249.64.0/27', '203.0.113.5']]);
});

test('the edge hears an empty list when nothing is allowed', function () {
    $this->withToken('edge-token')
        ->getJson(route('edge.allowed'))
        ->assertOk()
        ->assertExactJson(['allow' => []]);
});
