<?php

use App\Addons\Addons;
use App\Models\AddonSwitch;
use App\Models\Administrator;
use App\Models\User;
use Livewire\Livewire;

function administrator(): User
{
    return Administrator::factory()->create()->user;
}

test('guests are sent to log in, and users who aren\'t administrators are refused', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
})->with(['admin.addons', 'admin.administrators']);

test('only administrators see the Admin link in the user menu', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSeeHtml('data-test="admin-link"');

    $this->actingAs(administrator())->get(route('dashboard'))->assertSeeHtml('data-test="admin-link"');
});

test('turning an add-on off records who did it, and turning it on forgets the switch', function () {
    $admin = administrator();
    $this->actingAs($admin);

    Livewire::test('pages::admin.addons')
        ->call('confirm', 'off', 'fingerprints')
        ->assertSet('confirming', 'fingerprints')
        ->call('apply')
        ->assertSeeHtml('data-test="addon-turn-on"');

    expect(AddonSwitch::sole())
        ->slug->toBe('fingerprints')
        ->turned_off_by_id->toBe($admin->id)
        ->removal_requested_at->toBeNull();

    $this->get(route('fingerprints.index'))->assertNotFound();

    Livewire::test('pages::admin.addons')->call('turnOn', 'fingerprints');

    expect(AddonSwitch::count())->toBe(0);
    $this->get(route('fingerprints.index'))->assertOk();
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('removing an add-on turns it off and gives the Composer command for the next deploy, until it\'s kept', function () {
    $admin = administrator();
    $this->actingAs($admin);

    Livewire::test('pages::admin.addons')
        ->call('confirm', 'remove', 'fingerprints')
        ->call('apply')
        ->assertSeeHtml('data-test="addons-awaiting-deploy"')
        ->assertSee('composer remove nicetry/fingerprints')
        ->call('cancelRemoval', 'fingerprints')
        ->assertDontSeeHtml('data-test="addons-awaiting-deploy"');

    expect(AddonSwitch::sole())
        ->isOff()->toBeTrue()
        ->isAwaitingRemoval()->toBeFalse();
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('the Add-ons page links to the marketplace and explains installing the way this installation is built', function () {
    $this->actingAs(administrator());
    config()->set('addons.marketplace.url', 'https://market.example');

    config()->set('addons.install.via', 'docker');
    $this->get(route('admin.addons'))
        ->assertSeeHtml('href="https://market.example/marketplace"')
        ->assertSee('nicetrybot addons add captchas threats')
        ->assertSee('nicetrybot artisan addons:licence')
        ->assertDontSee('composer require');

    config()->set('addons.install.via', 'composer');
    $this->get(route('admin.addons'))
        ->assertSeeHtml('href="https://market.example/marketplace"')
        ->assertSee('composer require nicetry/captchas')
        ->assertSee('php artisan addons:licence')
        ->assertDontSee('nicetrybot addons');
});

test('a switch for an add-on the deploy removed is listed until dismissed, and an installed one can\'t be dismissed', function () {
    AddonSwitch::factory()->awaitingRemoval()->create(['slug' => 'gone-addon']);
    AddonSwitch::factory()->create(['slug' => 'fingerprints']);
    $this->actingAs(administrator());

    Livewire::test('pages::admin.addons')
        ->assertSeeInOrder(['gone-addon', 'removed by a deploy, as asked'])
        ->call('dismiss', 'gone-addon')
        ->assertDontSee('gone-addon')
        ->call('dismiss', 'fingerprints')
        ->assertNotFound();

    expect(AddonSwitch::pluck('slug')->all())->toBe(['fingerprints']);
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('only an installed add-on can be switched, and only off or removed through a confirmation', function (string $action, string $slug) {
    $this->actingAs(administrator());

    Livewire::test('pages::admin.addons')->call('confirm', $action, $slug)->assertNotFound();
})->with([
    'unknown add-on' => ['off', 'not-installed'],
    'unknown action' => ['delete', 'fingerprints'],
]);

test('a user who isn\'t an administrator can\'t switch add-ons or grant administration', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::admin.addons')->call('confirm', 'off', 'fingerprints')->assertForbidden();
    Livewire::test('pages::admin.administrators')->set('email', $user->email)->call('grant')->assertForbidden();

    expect(AddonSwitch::count())->toBe(0)
        ->and(Administrator::count())->toBe(0);
});

test('an administrator makes a user who signed up an administrator, by email', function () {
    $admin = administrator();
    $newcomer = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.administrators')
        ->set('email', 'nobody@example.com')
        ->call('grant')
        ->assertHasErrors(['email' => 'No user has that email address. They need to sign up first.'])
        ->set('email', $newcomer->email)
        ->call('grant')
        ->assertHasNoErrors()
        ->assertSet('email', '');

    expect($newcomer->fresh()->isAdministrator())->toBeTrue()
        ->and($newcomer->administrator->granted_by_id)->toBe($admin->id);
});

test('an administrator removes another, but not themselves', function () {
    $admin = administrator();
    $other = Administrator::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.administrators')
        ->call('revoke', $admin->administrator->id)
        ->assertForbidden();

    Livewire::test('pages::admin.administrators')->call('revoke', $other->id);

    expect(Administrator::pluck('user_id')->all())->toBe([$admin->id]);
});

test('admin:grant makes the first administrator from the command line', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
    $this->artisan('admin:grant', ['email' => 'ops@example.com'])->expectsOutput('ops@example.com is now an administrator.')->assertSuccessful();

    expect($user->fresh()->isAdministrator())->toBeTrue();
});
