<?php

use App\Addons\AddonSwitchboard;
use App\Models\Domain;
use App\Models\User;
use App\Support\SharedOrigins;
use App\Support\WorkspaceMenu;
use App\Support\WorkspaceMenuItem;
use Illuminate\Container\Container;

test('the cog at the top right leads to domains and origins, then visitor data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertSeeHtml('data-test="workspace-menu"')
        ->assertSeeInOrder([route('domains.index'), route('visitor-data.index')])
        ->assertSee(route('visitor-data.index'))
        ->assertDontSeeHtml('data-test="workspace-menu-alert"');
});

test('without shared origins, domains and origins counts every one of the team\'s domains', function () {
    $this->app->instance(SharedOrigins::class, new SharedOrigins(new Container, app(AddonSwitchboard::class)));
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->count(3)->create();
    Domain::factory()->create();
    $this->actingAs($user);

    $domains = collect(app(WorkspaceMenu::class)->items())->first(fn (WorkspaceMenuItem $item) => $item->route === 'domains.index');

    expect(($domains->badge)())->toBe('3');
});

test('the cog is whole with nothing in its menu', function () {
    $this->app->instance(WorkspaceMenu::class, new WorkspaceMenu);
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="workspace-menu"')
        ->assertDontSeeHtml('data-test="nav-domains"')
        ->assertDontSeeHtml('data-test="workspace-menu-alert"');
});
