<?php

use App\Actions\Domains\AddDomain;
use App\Addons\DomainLimit;
use App\Addons\InvalidLicenceKey;
use App\Models\Administrator;
use App\Models\Domain;
use App\Models\DomainPack;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NiceTry\Origins\Models\Origin;

/**
 * A More domains key as the marketplace issues it, signed with the test's keypair.
 *
 * @param  array<string, mixed>  $payload
 */
function domainPackKey(string $keypair, string $id, array $payload = []): string
{
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $body = $encode(json_encode([
        'v' => 1,
        'id' => $id,
        'addon' => 'more-domains',
        'to' => 'Jordan Example',
        'issued' => '2026-10-26',
        'updates_until' => '2027-10-26',
        ...$payload,
    ], JSON_UNESCAPED_SLASHES));

    return 'ntb1.'.$body.'.'.$encode(sodium_crypto_sign_detached('ntb1.'.$body, sodium_crypto_sign_secretkey($keypair)));
}

function addAnotherDomain(User $user, string $hostname = 'one-more.example.com'): Domain
{
    return app(AddDomain::class)->handle($user->currentTeam, [
        'hostname' => $hostname,
        'origin_url' => 'https://origin.example.net',
        'host_header_mode' => 'pass_through',
    ]);
}

beforeEach(function () {
    $this->keypair = sodium_crypto_sign_keypair();

    config([
        'nicetry.domain_limit' => 10,
        'addons.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
        'addons.marketplace.url' => 'https://marketplace.test',
    ]);

    // What the marketplace says about any licence, until a test changes it.
    $this->marketplaceSays = 'valid';

    Http::preventStrayRequests();
    Http::fake(['marketplace.test/licences/*' => fn () => Http::response(['status' => $this->marketplaceSays, 'updates_until' => '2027-10-26'])]);
});

test('the limit is 10 unless set, a number when set, and gone when unlimited', function (?string $value, ?int $limit) {
    expect(configFileWith('nicetry.php', ['NICETRY_DOMAIN_LIMIT' => $value])['domain_limit'])->toBe($limit);
})->with([
    'unset' => [null, 10],
    'empty' => ['', 10],
    'a number' => ['25', 25],
    'unlimited' => ['unlimited', null],
]);

test('the eleventh domain is refused, saying how to get more', function () {
    $user = User::factory()->create();
    Domain::factory()->count(10)->for($user->currentTeam)->create();

    expect(fn () => addAnotherDomain($user))->toThrow(ValidationException::class, 'Each More domains pack adds 100 more (https://marketplace.test/marketplace/more-domains)');
    expect(Domain::count())->toBe(10);

    $this->actingAs($user);
    Livewire::test('add-domain-modal')
        ->set('hostname', 'one-more.example.com')
        ->set('originUrl', 'https://origin.example.net')
        ->call('addDomain')
        ->assertHasErrors('hostname');
});

test('each pack adds 100 domains, and packs stack', function () {
    $user = User::factory()->create();
    Domain::factory()->count(10)->for($user->currentTeam)->create();
    $limit = app(DomainLimit::class);

    $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000001'), $user);
    expect($limit->allowed())->toBe(110);
    addAnotherDomain($user);

    $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000002'), $user);
    expect($limit->allowed())->toBe(210)->and($limit->used())->toBe(11);
});

test('a refunded pack stops counting, at the daily check', function () {
    $limit = app(DomainLimit::class);
    $pack = $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000003'));
    expect($limit->allowed())->toBe(110);

    $this->marketplaceSays = 'revoked';
    $this->artisan('addons:check-licences')->expectsOutputToContain('More domains pack 01JDOMAINPACK0000000000003: revoked')->assertSuccessful();

    expect($pack->fresh()->isValid())->toBeFalse()->and($limit->allowed())->toBe(10);
});

test('only an unused More domains key adds a pack', function () {
    $limit = app(DomainLimit::class);
    $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000004'));

    expect(fn () => $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000004')))->toThrow(InvalidLicenceKey::class, 'already added')
        ->and(fn () => $limit->enter(domainPackKey($this->keypair, '01JDOMAINPACK0000000000005', ['addon' => 'fingerprints'])))->toThrow(InvalidLicenceKey::class, 'not a More domains pack')
        ->and(fn () => $limit->enter(domainPackKey(sodium_crypto_sign_keypair(), '01JDOMAINPACK0000000000006')))->toThrow(InvalidLicenceKey::class);

    expect(DomainPack::count())->toBe(1);
});

test('with no limit, any number of domains can be added', function () {
    config(['nicetry.domain_limit' => null]);
    $user = User::factory()->create();
    Domain::factory()->count(12)->for($user->currentTeam)->create();

    expect(addAnotherDomain($user)->exists)->toBeTrue()
        ->and(app(DomainLimit::class)->allowed())->toBeNull();
});

test('hostnames behind an Origins origin don\'t count', function () {
    $user = User::factory()->create();
    $origin = Origin::factory()->for($user->currentTeam)->create();
    Domain::factory()->count(9)->for($user->currentTeam)->create();
    Domain::factory()->count(5)->for($user->currentTeam)->create(['origin_id' => $origin->id]);

    expect(app(DomainLimit::class)->used())->toBe(9);
    addAnotherDomain($user);
    expect(fn () => addAnotherDomain($user, 'two-more.example.com'))->toThrow(ValidationException::class);
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('an administrator adds and removes packs in Admin → Add-ons', function () {
    $admin = User::factory()->create();
    Administrator::query()->create(['user_id' => $admin->id]);
    Domain::factory()->count(3)->for($admin->currentTeam)->create();
    $this->actingAs($admin);

    $this->get(route('admin.addons'))->assertOk()->assertSee('data-test="domain-packs"', false)->assertSee('3 of 10');

    $component = Livewire::test('domain-packs')
        ->set('packKey', domainPackKey($this->keypair, '01JDOMAINPACK0000000000007'))
        ->call('addPack')
        ->assertHasNoErrors()
        ->assertSee('3 of 110')
        ->set('packKey', 'ntb1.not.a-key')
        ->call('addPack')
        ->assertHasErrors('packKey');

    $component->call('removePack', DomainPack::sole()->id)->assertSee('3 of 10');
    expect(DomainPack::count())->toBe(0);
});

test('only administrators manage packs', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('domain-packs')
        ->set('packKey', domainPackKey($this->keypair, '01JDOMAINPACK0000000000008'))
        ->call('addPack')
        ->assertForbidden();
    $this->get(route('admin.addons'))->assertForbidden();

    expect(DomainPack::count())->toBe(0);
});
