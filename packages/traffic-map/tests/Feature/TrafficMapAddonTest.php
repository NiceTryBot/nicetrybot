<?php

use App\Addons\Addons;
use App\Models\User;
use App\Support\Nav;
use App\Support\NavItem;

test('the add-on is installed as a free one, with a Traffic Map page in the sidebar', function () {
    expect(app(Addons::class)->find('traffic-map'))
        ->package->toBe('nicetry/traffic-map')
        ->name->toBe('Traffic Map')
        ->paid->toBeFalse();

    $routes = array_map(fn (NavItem $item) => $item->route, app(Nav::class)->items());

    expect($routes)->toContain('traffic-map.index');

    $user = User::factory()->create();

    expect(route('traffic-map.index', $user->currentTeam, false))->toBe('/'.$user->currentTeam->slug.'/traffic-map');

    $this->actingAs($user)->get(route('traffic-map.index'))->assertOk()->assertSee('Traffic Map');
});

test('the page 404s for a non-member of the team in the url', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('traffic-map.index', $owner->currentTeam))->assertForbidden();
});
