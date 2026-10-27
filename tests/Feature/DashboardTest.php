<?php

use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('the dashboard invites teams without domains to add one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Add your first domain');
});

test('the dashboard shows recently caught requests for the team\'s domains', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'claims.example.com']);
    EdgeRequest::factory()->for($domain)->probe()->create(['client_ip' => '203.0.113.42']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('claims.example.com')
        ->assertSee('203.0.113.42')
        ->assertDontSee('Add your first domain');
});

test('the kept off your origin metric links to those requests in the activity log', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['period' => '7d']))
        ->assertOk()
        ->assertSee(route('activity.index', ['action' => 'kept-off', 'period' => '7d']), escape: true);
});

test('the dashboard keeps its counts for a minute and serves them from the cache', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'claims.example.com']);
    EdgeRequest::factory()->for($domain)->count(3)->probe()->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk()->assertSeeInOrder(['claims.example.com', '/.env', 'Scanner probe']);

    EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'later.example.com']))->count(5)->create();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['claims.example.com', '/.env', 'Scanner probe'])
        ->assertDontSee('later.example.com');
});
