<?php

use App\Actions\Domains\ResolveHostname;
use App\Addons\AddonSwitchboard;
use App\Models\Domain;
use App\Support\SharedOrigins;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a live domain resolves by its name or its preview, www. only when it serves www.', function () {
    $shop = Domain::factory()->verified()->servingWww()->create(['hostname' => 'shop.example.com']);
    $blog = Domain::factory()->paused()->create(['hostname' => 'blog.example.com']);
    Domain::factory()->create(['hostname' => 'pending.example.com']);

    $resolve = app(ResolveHostname::class);

    expect($resolve->handle(' Shop.Example.com. ')?->is($shop))->toBeTrue()
        ->and($resolve->handle('shop-example-com.preview.example')?->is($shop))->toBeTrue()
        ->and($resolve->handle('www.shop.example.com')?->is($shop))->toBeTrue()
        ->and($resolve->handle('blog.example.com')?->is($blog))->toBeTrue()
        ->and($resolve->handle('www.blog.example.com'))->toBeNull()
        ->and($resolve->handle('pending.example.com'))->toBeNull()
        ->and($resolve->handle('not a host'))->toBeNull();
});

test('without shared origins, a hostname nobody registered is nobody\'s, and no one is asked', function () {
    $this->app->instance(SharedOrigins::class, new SharedOrigins(new Container, app(AddonSwitchboard::class)));
    Domain::factory()->verified()->create(['hostname' => 'shop.example.com']);

    $resolve = app(ResolveHostname::class);

    expect(app(SharedOrigins::class)->areKept())->toBeFalse()
        ->and($resolve->handle('stranger.example.org'))->toBeNull()
        ->and($resolve->handle('www.shop.example.com'))->toBeNull()
        ->and(Domain::count())->toBe(1);

    Http::assertNothingSent();
});
