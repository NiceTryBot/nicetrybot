<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Origins\Models\Origin;

test('an admin locks everything, engages, then releases sites one at a time', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $origin = Origin::factory()->for($team)->create();
    $site = Domain::factory()->for($team)->verified()->create(['origin_id' => $origin->id]);
    $own = Domain::factory()->for($team)->verified()->create();

    $this->actingAs($user);

    $page = Livewire::test('lockdown::index')
        ->call('lockEverything')
        ->assertSet('lockedCount', 2)
        ->call('engage');

    $lockdown = Lockdown::for($team);
    expect($lockdown->isEngaged())->toBeTrue();

    $page->call('release', $site->id)->assertSet('lockedCount', 1);
    expect($lockdown->covers($site))->toBeFalse()->and($lockdown->covers($own))->toBeTrue();

    $page->call('release', $own->id)->call('lock', $site->id)->assertSet('lockedCount', 1);
    expect($lockdown->covers($site))->toBeTrue()->and($lockdown->covers($own))->toBeFalse();

    $page->call('lift');
    expect($lockdown->fresh()->isEngaged())->toBeFalse();
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('the page can\'t reach another team\'s sites or origins', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('lockdown::index')->call('release', Domain::factory()->create()->id)->assertNotFound();
    Livewire::test('lockdown::index')->call('toggleOrigin', Origin::factory()->create()->id)->assertNotFound();
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('allowed addresses are stored the way the edge reads them', function (string $typed, string $stored) {
    $this->actingAs(User::factory()->create());

    Livewire::test('lockdown::index')
        ->set('range', $typed)
        ->call('addAllowedIp')
        ->assertHasNoErrors();

    expect(Lockdown::sole()->allowedIps()->value('range'))->toBe($stored);
})->with([
    'bare IPv4' => ['203.0.113.4', '203.0.113.4'],
    'IPv4 range from a host address' => ['198.51.100.7/24', '198.51.100.0/24'],
    'IPv6, upper case' => ['2001:DB8:0:0::1', '2001:db8::1'],
    'IPv6 range' => ['2001:db8:abcd:12::/48', '2001:db8:abcd::/48'],
]);

test('an allowed address must be an IP or range, and only once', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('lockdown::index')
        ->set('range', '203.0.113.300')
        ->call('addAllowedIp')
        ->assertHasErrors('range')
        ->set('range', '10.0.0.0/33')
        ->call('addAllowedIp')
        ->assertHasErrors('range')
        ->set('range', '203.0.113.4')
        ->call('addAllowedIp')
        ->assertHasNoErrors()
        ->set('range', '203.0.113.4/32')
        ->call('addAllowedIp')
        ->assertHasNoErrors()
        ->set('range', '203.0.113.4')
        ->call('addAllowedIp')
        ->assertHasErrors(['range' => 'unique']);
});

test('revoking passes signs future ones with a new secret', function () {
    $this->actingAs($user = User::factory()->create());
    $before = Lockdown::for($user->currentTeam)->secret;

    Livewire::test('lockdown::index')->call('revokePasses');

    expect(Lockdown::for($user->currentTeam)->secret)->not->toBe($before)->toHaveLength(64);
});

test('members see the lockdown but can\'t change it', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)->get(route('lockdown.index'))->assertOk()->assertDontSee(__('Engage lockdown'));

    Livewire::test('lockdown::index')->call('engage')->assertForbidden();
    Livewire::test('lockdown::index')->call('lockEverything')->assertForbidden();
    Livewire::test('lockdown::index')->set('range', '203.0.113.4')->call('addAllowedIp')->assertForbidden();

    expect(Lockdown::for($team)->isEngaged())->toBeFalse();
});

test('the preview shows the maintenance page the edge serves', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('lockdown.preview'))
        ->assertOk()
        ->assertSee('Scheduled Maintenance')
        ->assertDontSee('NiceTry');
});

test('a team\'s own page is what the edge and the preview serve, until it goes back to the stock one', function () {
    $this->actingAs($user = User::factory()->create());
    $lockdown = Lockdown::factory()->for($user->currentTeam)->engaged()->create();
    $page = '<!doctype html><title>Back soon</title><h1>Back soon</h1>';

    $component = Livewire::test('lockdown::index')
        ->assertSet('pageHtml', Lockdown::maintenancePage())
        ->set('pageHtml', $page)
        ->call('savePage')
        ->assertHasNoErrors();

    expect($lockdown->fresh()->edgePayload()['html'])->toBe($page);
    $this->get(route('lockdown.preview'))->assertOk()->assertSee('Back soon');

    $component->call('useStockPage')->call('savePage');

    expect($lockdown->fresh()->page_html)->toBeNull();
    $component->set('pageHtml', '  ')->call('savePage')->assertSet('pageHtml', Lockdown::maintenancePage());
});

test('a page over the size limit isn\'t saved, and members can\'t save one', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('lockdown::index')
        ->set('pageHtml', str_repeat('x', Lockdown::MAX_PAGE_BYTES + 1))
        ->call('savePage')
        ->assertHasErrors('pageHtml');

    expect(Lockdown::for($user->currentTeam)->page_html)->toBeNull();

    $member = User::factory()->create();
    $user->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($user->currentTeam);

    $this->actingAs($member);

    Livewire::test('lockdown::index')->set('pageHtml', '<h1>Mine</h1>')->call('savePage')->assertForbidden();
});
