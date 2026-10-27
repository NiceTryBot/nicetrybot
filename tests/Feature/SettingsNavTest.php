<?php

use App\Models\User;

test('the settings menu lists the core\'s settings, in order', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSeeInOrder([
            route('profile.edit'),
            route('security.edit'),
            route('teams.index'),
            route('appearance.edit'),
            route('ai.edit'),
        ]);
});
