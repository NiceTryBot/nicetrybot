<?php

use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;

test('the domains page lists only the current team\'s domains', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    Domain::factory()->create(['hostname' => 'someone-else.example.com']);

    $this->actingAs($user)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertSee('claims.example.com')
        ->assertDontSee('someone-else.example.com');
});

test('a domain the edge has never logged a request for says it is waiting instead of showing zeros', function () {
    $user = User::factory()->create();
    Domain::factory()->verified()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);

    $this->actingAs($user)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertSee('No traffic recorded yet')
        ->assertDontSee('Suspicious');
});

test('a domain with logged requests shows its counts for the last 24 hours', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->verified()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    EdgeRequest::factory()->count(3)->for($domain)->create(['occurred_at' => now()->subHour()]);

    $this->actingAs($user)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertDontSee('No traffic recorded yet')
        ->assertSeeInOrder(['Requests', '3']);
});
