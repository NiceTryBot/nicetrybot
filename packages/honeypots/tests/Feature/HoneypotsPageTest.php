<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotTrap;

test('an admin adds a honeypot, then a trap that sends api and admin 404s to it on one domain', function () {
    $user = User::factory()->create();
    $shop = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'shop.example']);
    $this->actingAs($user);

    $this->get(route('honeypots.index'))->assertOk()->assertSee('No honeypots yet.');

    Livewire::test('honeypots::index')
        ->call('createHoneypot')
        ->set('name', 'Galah')
        ->set('origin', 'https://203-0-113-7.sslip.io/')
        ->set('timeoutSeconds', 20)
        ->call('saveHoneypot')
        ->assertHasNoErrors();

    $galah = Honeypot::sole();

    expect($galah->team_id)->toBe($user->currentTeam->id)
        ->and($galah->origin)->toBe('https://203-0-113-7.sslip.io')
        ->and($galah->timeout_seconds)->toBe(20)
        ->and(strlen($galah->secret))->toBe(48);

    Livewire::test('honeypots::index')
        ->assertSee($galah->secret)
        ->call('createTrap')
        ->assertSet('honeypotId', $galah->id)
        ->set('patterns', "api/*\n  admin/*  \n\napi/*\nre:\\.(env|git)")
        ->set('domainId', $shop->id)
        ->call('saveTrap')
        ->assertHasNoErrors();

    $trap = HoneypotTrap::sole();

    expect($trap->patterns)->toBe(['api/*', 'admin/*', 're:\.(env|git)'])
        ->and($trap->domain_id)->toBe($shop->id)
        ->and($trap->honeypot_id)->toBe($galah->id)
        ->and($trap->enabled)->toBeTrue();

    Livewire::test('honeypots::index')->call('toggleTrap', $trap->id)->call('rotateSecret', $galah->id);

    expect($trap->fresh()->enabled)->toBeFalse()
        ->and($galah->fresh()->secret)->not->toBe($galah->secret);
});

test('a trap needs a path, regular expressions the edge can run, and the team\'s own honeypot', function (string $patterns, string $error) {
    $user = User::factory()->create();
    Honeypot::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);

    Livewire::test('honeypots::index')
        ->call('createTrap')
        ->set('patterns', $patterns)
        ->call('saveTrap')
        ->assertHasErrors(['patterns'])
        ->assertSee($error);

    expect(HoneypotTrap::count())->toBe(0);
})->with([
    'nothing' => ["\n  \n", 'Add at least one path pattern.'],
    'every 404' => ['/*', 'would trap every 404'],
    'a lookahead' => ['re:/(?!index)', 'lookarounds and backreferences aren’t supported'],
    'broken regex' => ['re:(unclosed', 'that isn’t a valid regular expression'],
]);

test('a honeypot needs a web address, and a trap can\'t use another team\'s honeypot', function () {
    $user = User::factory()->create();
    $theirs = Honeypot::factory()->create();
    $this->actingAs($user);

    Livewire::test('honeypots::index')
        ->call('createHoneypot')
        ->set('name', 'Galah')
        ->set('origin', 'ftp://203.0.113.7')
        ->call('saveHoneypot')
        ->assertHasErrors(['origin']);

    Honeypot::factory()->for($user->currentTeam)->create();

    Livewire::test('honeypots::index')
        ->call('createTrap')
        ->set('patterns', 'api/*')
        ->set('honeypotId', $theirs->id)
        ->call('saveTrap')
        ->assertHasErrors(['honeypotId']);
});

test('deleting a honeypot takes its traps with it', function () {
    $user = User::factory()->create();
    $galah = Honeypot::factory()->for($user->currentTeam)->create();
    HoneypotTrap::factory()->for($galah)->count(2)->create(['team_id' => $user->currentTeam->id]);
    $this->actingAs($user);

    Livewire::test('honeypots::index')
        ->call('confirmDelete', 'honeypot', $galah->id)
        ->call('delete');

    expect(Honeypot::count())->toBe(0)->and(HoneypotTrap::count())->toBe(0);
});

test('members see honeypots but not their secrets and can\'t change them, and no one reaches another team\'s', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $galah = Honeypot::factory()->for($team)->create(['name' => 'Galah']);
    $trap = HoneypotTrap::factory()->for($galah)->create(['team_id' => $team->id]);
    $theirs = HoneypotTrap::factory()->create();

    $this->actingAs($member)->get(route('honeypots.index'))->assertOk()
        ->assertSee('Galah')
        ->assertDontSee($galah->secret)
        ->assertDontSeeHtml('data-test="new-honeypot"');

    Livewire::test('honeypots::index')->call('createHoneypot')->assertForbidden();
    Livewire::test('honeypots::index')->call('toggleTrap', $trap->id)->assertForbidden();
    Livewire::test('honeypots::index')->call('rotateSecret', $galah->id)->assertForbidden();
    Livewire::test('honeypots::index')->call('editTrap', $theirs->id)->assertNotFound();

    expect($trap->fresh()->enabled)->toBeTrue();
});

test('a trap saves for every domain when the browser sends null for the choice', function () {
    $user = User::factory()->create();
    $shop = Domain::factory()->for($user->currentTeam)->verified()->create();
    $galah = Honeypot::factory()->for($user->currentTeam)->create();
    $trap = HoneypotTrap::factory()->for($galah)->create(['team_id' => $user->currentTeam->id, 'domain_id' => $shop->id]);
    $this->actingAs($user);

    $page = Livewire::test('honeypots::index')
        ->call('editTrap', $trap->id)
        ->set('domainId', null);

    // Livewire unsets a typed property it can't put null in, and reading it afterwards is a 500.
    expect((new ReflectionProperty($page->instance(), 'domainId'))->isInitialized($page->instance()))->toBeTrue();

    $page->call('saveTrap')->assertHasNoErrors();

    expect($trap->fresh()->domain_id)->toBeNull();
});
