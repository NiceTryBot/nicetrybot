<?php

use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Location;
use App\Models\User;
use App\Support\CountryCentroids;
use App\Support\TrafficMap;
use Livewire\Livewire;

test('the live map opens from analytics and keeps the whole team\'s live tail going', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('analytics.map'))
        ->assertOk()
        ->assertSee('Live map')
        ->assertSee('tm-icon-probe-creds')
        ->assertSeeHtml('data-test="traffic-map"');

    $tail = $user->currentTeam->liveTails()->sole();

    expect($tail->domain_id)->toBeNull()
        ->and($tail->user_id)->toBe($user->id)
        ->and($tail->expires_at->isFuture())->toBeTrue();
});

test('recent requests are placed on the map with their kind, newest after the last one seen', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    $montreal = Location::factory()->create(['country_code' => 'CA', 'region' => 'Quebec', 'city' => 'Montreal', 'latitude' => 45.5088, 'longitude' => -73.5878]);

    $page = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subSeconds(30), 'path' => '/court-documents', 'location_id' => $montreal->id]);
    $probe = EdgeRequest::factory()->for($domain)->probe()->create(['occurred_at' => now()->subSeconds(20), 'path' => '/.env', 'country_code' => 'FR']);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subMinutes(20)]);
    EdgeRequest::factory()->create(['occurred_at' => now()->subSeconds(10)]);

    $this->actingAs($user);

    $component = Livewire::test('pages::analytics.map');
    $first = $component->instance()->recent(0);

    expect(collect($first['requests'])->pluck('id')->all())->toBe([$page->id, $probe->id])
        ->and($first['last'])->toBe($probe->id)
        ->and($first['requests'][0])->toMatchArray([
            'icon' => 'page',
            'family' => 'page',
            'host' => 'claims.example.com',
            'path' => '/court-documents',
            'place' => 'Montreal, Quebec, CA',
            'x' => TrafficMap::project(45.5088, -73.5878)[0],
        ])
        ->and($first['requests'][1])->toMatchArray([
            'icon' => 'probe-creds',
            'family' => 'probe',
            'action' => 'observed',
            'y' => TrafficMap::project(...CountryCentroids::for('FR'))[1],
        ]);

    $later = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()]);

    expect(collect($component->instance()->recent($probe->id)['requests'])->pluck('id')->all())->toBe([$later->id]);
});

test('the map projection matches the drawn land', function () {
    // Reference points from d3-geo's geoEqualEarth fitted to the same box as the land path.
    expect(TrafficMap::project(37.77, -122.42))->toBe([195.1, 111.1])
        ->and(TrafficMap::project(-33.87, 151.2))->toBe([884.8, 369.2]);
});
