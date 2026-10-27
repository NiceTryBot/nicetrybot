<?php

use App\Actions\Clients\FindCurrentChromeVersion;
use App\Models\Domain;
use App\Models\EdgeRequest;
use Illuminate\Database\Eloquent\Factories\Sequence;

test('the current Chrome version is the newest one enough genuine Chrome clients ran this week', function () {
    $domain = Domain::factory()->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(155)->count(FindCurrentChromeVersion::MIN_CLIENTS)
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '198.51.100.'.($sequence->index + 1)])
        ->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(156)->count(FindCurrentChromeVersion::MIN_CLIENTS - 1)
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '192.0.2.'.($sequence->index + 1)])
        ->create();

    expect(app(FindCurrentChromeVersion::class)->handle())->toBe(155);
});

test('scripts copying a newer Chrome user agent do not move the current version', function () {
    $domain = Domain::factory()->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(155)->count(FindCurrentChromeVersion::MIN_CLIENTS)
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '198.51.100.'.($sequence->index + 1)])
        ->create();
    EdgeRequest::factory()->for($domain)->count(FindCurrentChromeVersion::MIN_CLIENTS)
        ->fromClient('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/160.0.0.0 Safari/537.36', 't13d131100_f57a46bbacb6_e7c285222651')
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '192.0.2.'.($sequence->index + 1)])
        ->create();

    expect(app(FindCurrentChromeVersion::class)->handle())->toBe(155);
});

test('the current Chrome version is unknown without enough genuine Chrome traffic', function () {
    EdgeRequest::factory()->genuineChrome(155)->count(FindCurrentChromeVersion::MIN_CLIENTS - 1)
        ->for(Domain::factory())
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '198.51.100.'.($sequence->index + 1)])
        ->create();

    expect(app(FindCurrentChromeVersion::class)->handle())->toBeNull();
});
