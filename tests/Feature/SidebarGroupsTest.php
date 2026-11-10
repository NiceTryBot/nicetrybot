<?php

use App\Enums\NavGroup;
use App\Models\AddonSwitch;
use App\Models\User;
use App\Support\Nav;
use App\Support\NavItem;

test('the sidebar draws the ungrouped links, then each group under its title', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Your workspace', 'Rules', 'Timeouts', 'Bot gym', 'Tags', 'Tags &amp; bans', 'Coming soon'], escape: false);
});

test('a group whose only links are off leaves no title behind', function () {
    AddonSwitch::factory()->create(['slug' => 'an-addon']);

    $nav = new Nav;
    $nav->add(new NavItem('Overview', 'squares-2x2', 'dashboard', order: 10));
    $nav->add(new NavItem('Bot gym', 'fire', 'gym.index', order: 90, group: NavGroup::Timeouts));
    $nav->addFromAddon('an-addon', new NavItem('Off', 'tag', 'tags.index', order: 70, group: NavGroup::Tags));

    expect(array_column($nav->groups(), 'group'))->toBe([null, NavGroup::Timeouts]);
});
