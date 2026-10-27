<?php

use App\Enums\LabelKind;
use App\Enums\TeamRole;
use App\Models\ClientLabel;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Livewire\Livewire;

test('traffic the team marked as its own is hidden by default, and can be shown again', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    ClientLabel::factory()->mine()->for($user->currentTeam)->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.40', 'name' => 'Me - Home']);
    ClientLabel::factory()->mine()->for($user->currentTeam)->create(['kind' => LabelKind::Ja4, 'value' => 't13d1518h2_8daaf6152771_e2d80978ab2e', 'name' => 'Dev Box']);
    ClientLabel::factory()->for($user->currentTeam)->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.7', 'name' => 'Known scanner']);

    EdgeRequest::factory()->for($domain)->create(['path' => '/from-home', 'client_ip' => '203.0.113.40']);
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['path' => '/from-dev-box', 'client_ip' => '198.51.100.20']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/from-scanner', 'client_ip' => '203.0.113.7']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/from-stranger', 'client_ip' => '198.51.100.99', 'ja4_id' => null]);

    $this->actingAs($user);

    $paths = fn ($component) => $component->instance()->requests->map(fn (EdgeRequest $request) => $request->path)->sort()->values()->all();

    $component = Livewire::test('pages::activity.index')
        ->assertSet('hide', 'mine')
        ->assertSeeHtml('data-test="hide-mine"');
    expect($paths($component))->toBe(['/from-scanner', '/from-stranger']);

    $component->call('toggleHide', 'mine')->assertSet('hide', '');
    expect($paths($component))->toBe(['/from-dev-box', '/from-home', '/from-scanner', '/from-stranger']);

    // Showing your own traffic is remembered; hiding it again is too.
    Livewire::test('pages::activity.index')->assertSet('hide', '')->call('toggleHide', 'mine');
    Livewire::test('pages::activity.index')->assertSet('hide', 'mine');
});

test('without any traffic marked as its own, the team sees no My traffic toggle', function () {
    $user = User::factory()->create();
    ClientLabel::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSet('hide', '')
        ->assertDontSeeHtml('data-test="hide-mine"');
});

test('a label can mark a value as your own traffic', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::clients.label-modal')
        ->call('open', 'ip', '203.0.113.40')
        ->set('name', 'Me - Home')
        ->set('isMine', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->currentTeam->clientLabels()->sole())->name->toBe('Me - Home')->is_mine->toBeTrue();

    // The label directory is loaded once per request; the next request reads it afresh.
    app()->forgetScopedInstances();

    Livewire::test('pages::clients.label-modal')
        ->call('open', 'ip', '203.0.113.40')
        ->assertSet('isMine', true)
        ->set('isMine', false)
        ->call('save');

    expect($user->currentTeam->clientLabels()->sole()->is_mine)->toBeFalse();
});

test('right-clicking a client can hide it from the view, or mark it as your own traffic', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['path' => '/from-phone', 'client_ip' => '198.51.100.124']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/from-stranger', 'client_ip' => '198.51.100.99', 'ja4_id' => null]);

    $this->actingAs($user);

    $paths = fn ($component) => $component->instance()->requests->map(fn (EdgeRequest $request) => $request->path)->sort()->values()->all();

    $component = Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-client-kind="ip"')
        ->assertSeeHtml('data-test="client-menu"');

    // Hide one IP from this view: a clause in the search, nothing saved.
    $component->call('excludeClient', 'ip', '198.51.100.124')->assertSet('search', "ip != '198.51.100.124'");
    expect($paths($component))->toBe(['/from-stranger']);
    $component->set('search', '');
    expect($paths($component))->toBe(['/from-phone', '/from-stranger']);

    // Hide a fingerprint the same way, keeping requests without one; nonsense is ignored.
    $component->call('excludeClient', 'ja4', 't13d1518h2_8daaf6152771_e2d80978ab2e')->call('excludeClient', 'ip', 'not-an-ip')
        ->assertSet('search', "(ja4 IS NULL OR ja4 != 't13d1518h2_8daaf6152771_e2d80978ab2e')");
    expect($paths($component))->toBe(['/from-stranger'])->and($user->currentTeam->clientLabels()->count())->toBe(0);
    $component->call('clearFilters');

    // Show only one client.
    $component->call('onlyClient', 'ip', '198.51.100.99')->assertSet('search', "ip = '198.51.100.99'");
    expect($paths($component))->toBe(['/from-stranger']);
    $component->call('clearFilters')->call('excludeClient', 'ip', '198.51.100.124');

    // Mark it as yours: named "Me", saved, and hidden from now on, which drops the clause hiding it.
    $component->call('markMine', 'ip', '198.51.100.124')->assertSet('hide', 'mine')->assertSet('search', '');
    expect($paths($component))->toBe(['/from-stranger'])
        ->and($user->currentTeam->clientLabels()->sole())->name->toBe('Me')->is_mine->toBeTrue();
});

test('members can hide a client from their view but not mark it as the team\'s traffic', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);

    $this->actingAs($member);

    Livewire::test('pages::activity.index')
        ->call('excludeClient', 'ip', '198.51.100.124')
        ->assertSet('search', "ip != '198.51.100.124'")
        ->call('markMine', 'ip', '198.51.100.124')
        ->assertForbidden();

    expect(ClientLabel::count())->toBe(0);
});
