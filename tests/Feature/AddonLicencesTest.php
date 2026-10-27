<?php

use App\Addons\Addons;
use App\Addons\InvalidLicenceKey;
use App\Addons\LicenceKey;
use App\Addons\Licences;
use App\Addons\LicenceState;
use App\Models\AddonLicence;
use App\Models\Administrator;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * A licence key as the marketplace issues it, signed with a keypair made for the test.
 *
 * @param  array<string, mixed>  $payload
 */
function signedLicenceKey(string $keypair, array $payload = []): string
{
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $body = $encode(json_encode([
        'v' => 1,
        'id' => '01JAZ3M4X5Y6Z7A8B9C0D1E2F3',
        'addon' => 'fingerprints',
        'to' => 'Jo Bloggs',
        'issued' => '2026-10-25',
        'updates_until' => '2027-10-25',
        ...$payload,
    ], JSON_UNESCAPED_SLASHES));

    return 'ntb1.'.$body.'.'.$encode(sodium_crypto_sign_detached('ntb1.'.$body, sodium_crypto_sign_secretkey($keypair)));
}

beforeEach(function () {
    // These tests license the paid Fingerprints add-on; without it installed there is nothing to license.
    if (! app(Addons::class)->has('fingerprints')) {
        $this->markTestSkipped('Needs the paid Fingerprints add-on (nicetry/fingerprints).');
    }

    $this->keypair = sodium_crypto_sign_keypair();

    config([
        'addons.licensing.enforce' => true,
        'addons.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
        'addons.marketplace.url' => 'https://marketplace.test',
    ]);

    Http::preventStrayRequests();
});

test('a paid add-on without a licence runs for its trial, then goes off until a key is entered', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = route('fingerprints.index', ['current_team' => $user->currentTeam]);

    $this->get($page)->assertOk();

    $licence = AddonLicence::query()->where('slug', 'fingerprints')->sole();
    expect($licence->status)->toBe(AddonLicence::MISSING)
        ->and($licence->first_seen_at)->not->toBeNull()
        ->and(app(Licences::class)->state(app(Addons::class)->find('fingerprints')))->toBe(LicenceState::Trial);

    $this->travel(15)->days();
    app()->forgetScopedInstances();

    $this->get($page)->assertNotFound();
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('fingerprints.index'));

    Http::fake(['https://marketplace.test/licences/*' => Http::response(['status' => 'valid', 'updates_until' => '2027-10-25'])]);
    app(Licences::class)->enter(app(Addons::class)->find('fingerprints'), signedLicenceKey($this->keypair), $user);
    app()->forgetScopedInstances();

    $this->get($page)->assertOk();
    $this->get(route('dashboard'))->assertSee(route('fingerprints.index'));

    $licence->refresh();
    expect($licence->status)->toBe(AddonLicence::VALID)
        ->and($licence->licensed_to)->toBe('Jo Bloggs')
        ->and($licence->licence_id)->toBe('01JAZ3M4X5Y6Z7A8B9C0D1E2F3')
        ->and($licence->checked_at)->not->toBeNull()
        ->and($licence->entered_by_id)->toBe($user->id)
        ->and($licence->first_seen_at->isBefore(now()->subDays(14)))->toBeTrue();
});

test('a key is proven before it is kept', function () {
    $licences = app(Licences::class);
    $fingerprints = app(Addons::class)->find('fingerprints');
    Http::fake();

    expect(fn () => $licences->enter($fingerprints, 'not a key'))->toThrow(InvalidLicenceKey::class, 'isn’t a NiceTryBot licence key')
        ->and(fn () => $licences->enter($fingerprints, signedLicenceKey(sodium_crypto_sign_keypair())))->toThrow(InvalidLicenceKey::class, 'signature')
        ->and(fn () => $licences->enter($fingerprints, signedLicenceKey($this->keypair, ['addon' => 'captchas'])))->toThrow(InvalidLicenceKey::class, 'for Captchas, not Fingerprints')
        ->and(AddonLicence::query()->whereNotNull('key')->exists())->toBeFalse();

    $key = LicenceKey::parse(signedLicenceKey($this->keypair));
    expect($key->addon)->toBe('fingerprints')
        ->and($key->to)->toBe('Jo Bloggs')
        ->and($key->updatesUntil->toDateString())->toBe('2027-10-25');
});

test('a licence the marketplace revokes turns its add-on off on the next check, and a new key turns it on', function () {
    $user = User::factory()->create();
    $fingerprints = app(Addons::class)->find('fingerprints');
    $page = route('fingerprints.index', ['current_team' => $user->currentTeam]);

    // What the marketplace answers, changed as the test goes (the first fake registered would otherwise win).
    $answer = ['status' => 'valid', 'updates_until' => '2027-10-25'];
    Http::fake(['https://marketplace.test/licences/*' => function () use (&$answer) {
        return Http::response($answer);
    }]);

    app(Licences::class)->enter($fingerprints, signedLicenceKey($this->keypair));
    $this->actingAs($user)->get($page)->assertOk();

    $answer = ['status' => 'revoked', 'updates_until' => '2027-10-25'];
    $this->artisan('addons:check-licences')->expectsOutputToContain('fingerprints: revoked')->assertSuccessful();
    app()->forgetScopedInstances();

    expect(app(Licences::class)->state($fingerprints))->toBe(LicenceState::Revoked);
    $this->get($page)->assertNotFound();

    $answer = ['status' => 'valid', 'updates_until' => '2027-12-01'];
    app(Licences::class)->enter($fingerprints, signedLicenceKey($this->keypair, ['id' => '01JAZ3M4X5Y6Z7A8B9C0D1E2F4']));
    app()->forgetScopedInstances();

    $this->get($page)->assertOk();
    expect(AddonLicence::query()->where('slug', 'fingerprints')->sole()->updates_until->toDateString())->toBe('2027-12-01');
});

test('an answer that isn’t the marketplace’s (a 404, an error page) leaves the last answer standing', function () {
    $fingerprints = app(Addons::class)->find('fingerprints');
    Http::fake(['https://marketplace.test/licences/*' => Http::response('<html>Not found</html>', 404)]);

    $licence = app(Licences::class)->enter($fingerprints, signedLicenceKey($this->keypair));

    expect($licence->isValid())->toBeTrue()
        ->and($licence->checked_at)->toBeNull()
        ->and(app(Licences::class)->unlicensed())->toBe([]);
});

test('when the marketplace can’t be reached the last answer stands', function () {
    $user = User::factory()->create();
    $fingerprints = app(Addons::class)->find('fingerprints');
    Http::fake(fn () => throw new ConnectionException('down'));

    $licence = app(Licences::class)->enter($fingerprints, signedLicenceKey($this->keypair));

    expect($licence->isValid())->toBeTrue()->and($licence->checked_at)->toBeNull();
    $this->actingAs($user)->get(route('fingerprints.index', ['current_team' => $user->currentTeam]))->assertOk();

    $this->artisan('addons:check-licences')->expectsOutputToContain('didn’t answer')->assertSuccessful();
    expect($licence->fresh()->isValid())->toBeTrue();
});

test('keys aren’t enforced in local and testing unless config says so', function () {
    config(['addons.licensing.enforce' => null]);
    $user = User::factory()->create();
    $fingerprints = app(Addons::class)->find('fingerprints');

    $this->travel(40)->days();
    $this->actingAs($user)->get(route('fingerprints.index', ['current_team' => $user->currentTeam]))->assertOk();

    expect(app(Licences::class)->enforced())->toBeFalse()
        ->and(app(Licences::class)->state($fingerprints))->toBe(LicenceState::Trial)
        ->and(app(Licences::class)->unlicensed())->toBe([])
        ->and(AddonLicence::query()->count())->toBe(0);
});

test('removing a key doesn’t start the trial over', function () {
    $fingerprints = app(Addons::class)->find('fingerprints');
    Http::fake(['https://marketplace.test/licences/*' => Http::response(['status' => 'valid', 'updates_until' => '2027-10-25'])]);

    app(Licences::class)->enter($fingerprints, signedLicenceKey($this->keypair));
    $this->travel(20)->days();
    app(Licences::class)->remove($fingerprints);
    app()->forgetScopedInstances();

    expect(app(Licences::class)->state($fingerprints))->toBe(LicenceState::TrialEnded)
        ->and(AddonLicence::query()->where('slug', 'fingerprints')->sole()->key)->toBeNull();
});

test('a licence entered on another server is read on the next request', function () {
    AddonLicence::factory()->create(['slug' => 'fingerprints', 'first_seen_at' => now()->subDays(30)]);
    $user = User::factory()->create();
    $page = route('fingerprints.index', ['current_team' => $user->currentTeam]);

    $this->actingAs($user)->get($page)->assertNotFound();

    // Another server entered a key: the row changed there, and the shared cache was cleared.
    AddonLicence::withoutEvents(fn () => AddonLicence::query()->where('slug', 'fingerprints')->update([
        'key' => 'ntb1.x.y', 'licence_id' => '01JTEST', 'status' => AddonLicence::VALID,
    ]));
    Cache::forget(Licences::CACHE_KEY);
    app()->forgetScopedInstances();

    $this->get($page)->assertOk();
});

test('the admin’s Add-ons page shows where each paid add-on stands and takes a key', function () {
    $administrator = Administrator::factory()->create()->user;
    Http::fake(['https://marketplace.test/licences/*' => Http::response(['status' => 'valid', 'updates_until' => '2027-10-25'])]);

    $this->actingAs($administrator)
        ->get(route('admin.addons'))
        ->assertOk()
        ->assertSee('Trial: 14 days left')
        ->assertSee('No licence key. It runs until')
        ->assertSee('Enter licence key…');

    Livewire::actingAs($administrator)
        ->test('pages::admin.addons')
        ->call('enterLicence', 'fingerprints')
        ->set('licenceKey', 'garbage')
        ->call('saveLicence')
        ->assertHasErrors(['licenceKey'])
        ->set('licenceKey', signedLicenceKey($this->keypair))
        ->call('saveLicence')
        ->assertHasNoErrors()
        ->assertSee('Licensed to Jo Bloggs.')
        ->assertSee('Updates until Oct 25, 2027.')
        ->assertSee('Replace key…')
        ->call('removeLicence', 'fingerprints')
        ->assertSee('Trial: 14 days left');

    // A free add-on needs none of this.
    Livewire::actingAs($administrator)
        ->test('pages::admin.addons')
        ->assertDontSeeHtml('wire:click="enterLicence(\'privacy-mode\')"')
        ->call('enterLicence', 'privacy-mode')
        ->assertNotFound();
});

test('an unlicensed add-on past its trial shows as off for want of a licence', function () {
    AddonLicence::factory()->create(['slug' => 'fingerprints', 'first_seen_at' => now()->subDays(30)]);

    $this->actingAs(Administrator::factory()->create()->user)
        ->get(route('admin.addons'))
        ->assertOk()
        ->assertSee('Off: no licence')
        ->assertSee('Trial ended');
});

test('the addons:licence command enters a key', function () {
    Http::fake(['https://marketplace.test/licences/*' => Http::response(['status' => 'valid', 'updates_until' => '2027-10-25'])]);

    $this->artisan('addons:licence', ['addon' => 'nope', 'key' => 'x'])->assertFailed();
    $this->artisan('addons:licence', ['addon' => 'fingerprints', 'key' => 'x'])->assertFailed();
    $this->artisan('addons:licence', ['addon' => 'privacy-mode', 'key' => 'x'])->expectsOutputToContain('free')->assertSuccessful();
    $this->artisan('addons:licence', ['addon' => 'fingerprints', 'key' => signedLicenceKey($this->keypair)])
        ->expectsOutputToContain('Fingerprints is licensed to Jo Bloggs, with updates until Oct 25, 2027.')
        ->assertSuccessful();

    expect(AddonLicence::query()->where('slug', 'fingerprints')->sole()->isValid())->toBeTrue();
});

test('addons:licence --missing lists the paid add-ons without a good key, for nicetrybot addons add to ask for', function () {
    Http::fake(['https://marketplace.test/licences/*' => Http::response(['status' => 'valid', 'updates_until' => '2027-10-25'])]);
    $paid = collect(app(Addons::class)->all())->filter->paid->pluck('slug');

    $this->artisan('addons:licence', ['--missing' => true])
        ->expectsOutput($paid->first())
        ->assertSuccessful();

    $this->artisan('addons:licence', ['addon' => 'fingerprints', 'key' => signedLicenceKey($this->keypair)])->assertSuccessful();

    $this->artisan('addons:licence', ['--missing' => true])
        ->doesntExpectOutput('fingerprints')
        ->doesntExpectOutput('privacy-mode')
        ->assertSuccessful();

    $this->artisan('addons:licence')->assertFailed();
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');
