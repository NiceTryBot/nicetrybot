<?php

use App\Actions\Clients\FindCurrentFirefoxReleases;
use Illuminate\Support\Facades\Http;

test('refreshing reports the Firefox versions it downloaded', function () {
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::response(['LATEST_FIREFOX_VERSION' => '158.0', 'FIREFOX_ESR' => '153.5.0esr', 'FIREFOX_ESR115' => '115.43.0esr'])]);

    $this->artisan('clients:refresh-firefox')
        ->expectsOutput('Firefox 158 is current; ESR 153, 115 still supported.')
        ->assertSuccessful();
});

test('refreshing fails, keeping what it had, when Mozilla can\'t be reached', function () {
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::failedConnection()]);

    $this->artisan('clients:refresh-firefox')->assertFailed();

    expect(app(FindCurrentFirefoxReleases::class)->handle()['current'])->toBeNull();
});
