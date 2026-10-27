<?php

use App\Models\Administrator;
use App\Support\Releases;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A self-hosted panel from the release image, running 1.2.0, that checks releases.example.
 */
function releaseImage(): void
{
    config()->set('addons.install.via', 'docker');
    config()->set('nicetry.release', '1.2.0');
    config()->set('nicetry.update_check', ['enabled' => true, 'releases' => 'https://releases.example/nicetrybot/releases']);
}

test('the check keeps the newest release that latest redirects to', function () {
    releaseImage();
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'releases.example/nicetrybot/releases/latest' => Http::response('', 302, ['Location' => 'https://releases.example/nicetrybot/releases/tag/v1.3.0']),
    ]);

    $this->artisan('nicetry:check-release')->assertSuccessful();

    expect(Cache::get(Releases::CACHE_KEY))->toBe([
        'version' => '1.3.0',
        'url' => 'https://releases.example/nicetrybot/releases/tag/v1.3.0',
        'checked_at' => now()->getTimestamp(),
    ]);
});

test('the check asks nothing unless this is a release image with the check on', function (string $key, mixed $value) {
    releaseImage();
    config()->set($key, $value);
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('nicetry:check-release')->assertSuccessful();

    Http::assertNothingSent();
    expect(Cache::get(Releases::CACHE_KEY))->toBeNull();
})->with([
    'run from source' => ['addons.install.via', 'composer'],
    'no release built in' => ['nicetry.release', null],
    'check turned off' => ['nicetry.update_check.enabled', false],
]);

test('an answer that names no release keeps the last one', function (int $status, array $headers) {
    releaseImage();
    $kept = ['version' => '1.2.5', 'url' => 'https://releases.example/nicetrybot/releases/tag/v1.2.5', 'checked_at' => 1];
    Cache::put(Releases::CACHE_KEY, $kept);
    Http::preventStrayRequests();
    Http::fake([
        'releases.example/nicetrybot/releases/latest' => Http::response('', $status, $headers),
    ]);

    $this->artisan('nicetry:check-release')
        ->expectsOutputToContain('the last answer stands')
        ->assertSuccessful();

    expect(Cache::get(Releases::CACHE_KEY))->toBe($kept);
})->with([
    'not a tag' => [302, ['Location' => 'https://releases.example/nicetrybot/releases']],
    'not a version' => [302, ['Location' => 'https://releases.example/nicetrybot/releases/tag/nightly']],
    'no redirect' => [200, []],
    'an error' => [500, []],
]);

test('administrators see the update notice while a newer release is kept', function () {
    releaseImage();
    Releases::remember('1.3.0', 'https://releases.example/nicetrybot/releases/tag/v1.3.0');

    $this->actingAs(Administrator::factory()->create()->user)
        ->get(route('admin.addons'))
        ->assertOk()
        ->assertSeeHtml('data-test="update-available"')
        ->assertSee('NiceTryBot 1.3.0 is out')
        ->assertSee('This panel runs 1.2.0.')
        ->assertSee('nicetrybot update')
        ->assertSeeHtml('href="https://releases.example/nicetrybot/releases/tag/v1.3.0"');
});

test('there\'s no update notice unless the kept release is newer', function (?string $latest) {
    releaseImage();
    if ($latest !== null) {
        Releases::remember($latest, 'https://releases.example/nicetrybot/releases/tag/v'.$latest);
    }

    $this->actingAs(Administrator::factory()->create()->user)
        ->get(route('admin.addons'))
        ->assertOk()
        ->assertDontSeeHtml('data-test="update-available"');
})->with([
    'the same' => ['1.2.0'],
    'older' => ['1.1.9'],
    'none kept' => [null],
]);
