<?php

use App\Actions\Clients\FindCurrentFirefoxReleases;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('the current Firefox and its supported ESR lines come from Mozilla\'s release list', function () {
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::response([
        'FIREFOX_DEVEDITION' => '159.0b2',
        'FIREFOX_ESR' => '153.5.0esr',
        'FIREFOX_ESR115' => '115.43.0esr',
        'FIREFOX_ESR_NEXT' => '',
        'FIREFOX_NIGHTLY' => '160.0a1',
        'LATEST_FIREFOX_VERSION' => '158.0',
    ])]);

    app(FindCurrentFirefoxReleases::class)->refresh();

    expect(app(FindCurrentFirefoxReleases::class)->handle())->toBe(['current' => 158, 'esr' => [153, 115]]);
});

test('Firefox\'s version is unknown until Mozilla\'s release list is downloaded', function () {
    expect(app(FindCurrentFirefoxReleases::class)->handle())->toBe(['current' => null, 'esr' => []]);
});

test('a failed download keeps the release list already downloaded', function () {
    Http::preventStrayRequests();
    Http::fakeSequence(FindCurrentFirefoxReleases::URL)
        ->push(['LATEST_FIREFOX_VERSION' => '158.0', 'FIREFOX_ESR' => '153.5.0esr'])
        ->push('Service Unavailable', 503);
    app(FindCurrentFirefoxReleases::class)->refresh();

    expect(fn () => app(FindCurrentFirefoxReleases::class)->refresh())->toThrow(RequestException::class);
    expect(app(FindCurrentFirefoxReleases::class)->handle())->toBe(['current' => 158, 'esr' => [153]]);
});
