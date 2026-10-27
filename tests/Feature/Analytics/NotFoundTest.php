<?php

use App\Enums\PathCategory;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;

test('the 404s page ranks domains and sorts paths into probes and browser assets', function () {
    $user = User::factory()->create();
    $busy = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'busy.example.com']);
    $quiet = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'quiet.example.com']);
    EdgeRequest::factory()->for($busy)->count(3)->probe()->create();
    EdgeRequest::factory()->for($quiet)->notFound('/apple-touch-icon.png')->create();
    EdgeRequest::factory()->for($quiet)->create(['path' => '/claim']);

    $this->actingAs($user)
        ->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['busy.example.com', 'quiet.example.com'])
        ->assertSeeInOrder(['/.env', 'Scanner probe', '/apple-touch-icon.png', 'Browser asset'])
        ->assertDontSee('/claim');
});

test('the 404s page sorts paths by the category stored on them, including one chosen by hand', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->count(2)->notFound('/old-landing')->create()->first();
    $request->requestPath->update(['category' => PathCategory::ProbeAdmin, 'categorized_by' => $user->id, 'categorized_at' => now()]);

    $this->actingAs($user)
        ->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['/old-landing', 'Scanner probe']);
});

test('the 404s page keeps its summary for a minute and serves it from the cache', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(2)->probe()->create();

    $this->actingAs($user);

    $this->get(route('not-found.index'))->assertOk()->assertSeeInOrder(['/.env', 'Scanner probe']);

    EdgeRequest::factory()->for($domain)->notFound('/later-page')->create();

    $this->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['/.env', 'Scanner probe'])
        ->assertDontSee('/later-page');
});
