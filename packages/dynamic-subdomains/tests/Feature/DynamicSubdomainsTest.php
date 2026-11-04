<?php

use App\Actions\Domains\ResolveHostname;
use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomain;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomainParent;
use NiceTry\RequestMonitor\Models\ResponseCapture;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge asking about a name under a domain with dynamic subdomains adds it, live, with its origin under the same name', function () {
    $parent = Domain::factory()->verified()->create([
        'hostname' => 'safe-google.test',
        'origin_url' => 'https://server.local',
        'host_header_mode' => HostHeaderMode::Rewrite,
        'protection_mode' => ProtectionMode::Enforce,
        'hsts_policy' => HstsPolicy::Preload,
        'cache_assets' => true,
    ]);
    DynamicSubdomainParent::factory()->create(['domain_id' => $parent->id]);

    $this->withToken('edge-token')
        ->getJson(route('edge.origins.resolve', ['host' => 'Foo.safe-google.test']))
        ->assertOk()
        ->assertJsonPath('host', 'foo.safe-google.test')
        ->assertJsonPath('upstream', 'foo.server.local:443')
        ->assertJsonPath('host_header', 'foo.server.local')
        ->assertJsonPath('secret', $parent->origin_secret);

    $subdomain = Domain::query()->where('hostname', 'foo.safe-google.test')->sole();

    expect($subdomain)
        ->team_id->toBe($parent->team_id)
        ->status->toBe(DomainStatus::Active)
        ->verified_at->not->toBeNull()
        ->protection_mode->toBe(ProtectionMode::Enforce)
        ->hsts_policy->toBe(HstsPolicy::Preload)
        ->cache_assets->toBeTrue()
        ->and(DynamicSubdomain::query()->where('domain_id', $subdomain->id)->value('parent_domain_id'))->toBe($parent->id);

    // A label starting with digits, like a capture-the-flag level's hash, keeps every character.
    expect(app(ResolveHostname::class)->handle('66bc0b53.safe-google.test')->origin_url)->toBe('https://66bc0b53.server.local');

    // Asked again (the certificate, then the request), it's the same domain.
    expect(app(ResolveHostname::class)->handle('foo.safe-google.test')?->is($subdomain))->toBeTrue()
        ->and(Domain::query()->count())->toBe(3);
});

test('only a name one label under a live domain with dynamic subdomains on is added', function () {
    $on = Domain::factory()->verified()->create(['hostname' => 'on.example']);
    DynamicSubdomainParent::factory()->create(['domain_id' => $on->id]);
    Domain::factory()->verified()->create(['hostname' => 'off.example']);
    $pending = Domain::factory()->create(['hostname' => 'pending.example']);
    DynamicSubdomainParent::factory()->create(['domain_id' => $pending->id]);

    $resolve = app(ResolveHostname::class);

    expect($resolve->handle('foo.off.example'))->toBeNull()
        ->and($resolve->handle('foo.pending.example'))->toBeNull()
        ->and($resolve->handle('a.b.on.example'))->toBeNull()
        ->and(Domain::query()->count())->toBe(3);
});

test('a domain stops adding subdomains at its limit', function () {
    config(['dynamic-subdomains.limit' => 1]);
    $parent = Domain::factory()->verified()->create(['hostname' => 'shop.example']);
    DynamicSubdomainParent::factory()->create(['domain_id' => $parent->id]);

    $resolve = app(ResolveHostname::class);

    expect($resolve->handle('one.shop.example'))->not->toBeNull()
        ->and($resolve->handle('two.shop.example'))->toBeNull();
});

test('a subdomain gets its own copy of each capture still recording on the domain', function () {
    $parent = Domain::factory()->verified()->create(['hostname' => 'ctf.example']);
    DynamicSubdomainParent::factory()->create(['domain_id' => $parent->id]);
    $recording = ResponseCapture::factory()->create(['team_id' => $parent->team_id, 'domain_id' => $parent->id, 'method' => null, 'path_pattern' => '/*', 'max_responses' => 2000]);
    ResponseCapture::factory()->expired()->create(['team_id' => $parent->team_id, 'domain_id' => $parent->id]);

    $subdomain = app(ResolveHostname::class)->handle('level1.ctf.example');
    $copy = ResponseCapture::query()->where('domain_id', $subdomain->id)->sole();

    expect($copy)
        ->team_id->toBe($parent->team_id)
        ->path_pattern->toBe('/*')
        ->max_responses->toBe(2000)
        ->records_requests->toBeTrue()
        ->and($copy->expires_at->equalTo($recording->expires_at))->toBeTrue();
})->skip(fn () => ! class_exists(ResponseCapture::class), 'Needs the paid Request Monitor add-on (nicetry/request-monitor).');

test('an origin reached by IP address is shared by the subdomains, and a set host header gets the name in front', function () {
    $byIp = Domain::factory()->verified()->create(['hostname' => 'ip.example', 'origin_url' => 'http://203.0.113.9:8080']);
    $withHeader = Domain::factory()->verified()->create([
        'hostname' => 'header.example',
        'origin_url' => 'https://203.0.113.10',
        'host_header_mode' => HostHeaderMode::Rewrite,
        'origin_host_header' => 'app.internal',
    ]);
    DynamicSubdomainParent::factory()->create(['domain_id' => $byIp->id]);
    DynamicSubdomainParent::factory()->create(['domain_id' => $withHeader->id]);

    $resolve = app(ResolveHostname::class);

    expect($resolve->handle('foo.ip.example')->origin_url)->toBe('http://203.0.113.9:8080')
        ->and($resolve->handle('foo.header.example')->originHostHeader())->toBe('foo.app.internal');
});

test('a domain\'s page turns dynamic subdomains on and off and lists what it added', function () {
    $user = User::factory()->create();
    $parent = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'shop.example', 'origin_url' => 'https://origin.example']);
    $this->actingAs($user);

    $this->get(route('domains.show', $parent))
        ->assertOk()
        ->assertSeeHtml('data-test="domain-dynamic-subdomains"')
        ->assertSee('https://foo.origin.example');

    Livewire::test('dynamic-subdomains::domain-panel', ['domain' => $parent])->set('enabled', true);
    expect(DynamicSubdomainParent::query()->where('domain_id', $parent->id)->exists())->toBeTrue();

    app(ResolveHostname::class)->handle('foo.shop.example');

    Livewire::test('dynamic-subdomains::domain-panel', ['domain' => $parent])
        ->assertSet('enabled', true)
        ->assertSee('foo.shop.example')
        ->set('enabled', false);

    expect(DynamicSubdomainParent::query()->where('domain_id', $parent->id)->exists())->toBeFalse()
        ->and(Domain::query()->where('hostname', 'foo.shop.example')->exists())->toBeTrue()
        ->and(app(ResolveHostname::class)->handle('bar.shop.example'))->toBeNull();
});

test('members can\'t turn dynamic subdomains on, nor reach another team\'s domain', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $domain = Domain::factory()->for($team)->verified()->create();
    $this->actingAs($member);

    Livewire::test('dynamic-subdomains::domain-panel', ['domain' => $domain])
        ->set('enabled', true)
        ->assertForbidden();

    expect(DynamicSubdomainParent::query()->exists())->toBeFalse();

    Livewire::test('dynamic-subdomains::domain-panel', ['domain' => Domain::factory()->verified()->create()])->assertNotFound();
});
