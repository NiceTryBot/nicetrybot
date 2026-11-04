<?php

use App\Data\RequestQuery;
use App\Enums\RequestAction;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use NiceTry\TrafficMap\Actions\SummarizeTrafficMap;
use NiceTry\TrafficMap\Data\TreemapTile;
use NiceTry\TrafficMap\Enums\MapDimension;

/**
 * @return Collection<string, TreemapTile>
 */
function tilesByKey(array $tiles): Collection
{
    return collect($tiles)->keyBy('key');
}

test('it groups a team\'s recent requests into tiles, sized by volume and counting the suspect ones', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example.com']);

    EdgeRequest::factory()->count(3)->for($domain)->create([
        'action' => RequestAction::Passed, 'country_code' => 'US', 'occurred_at' => now()->subMinutes(5),
    ]);
    EdgeRequest::factory()->count(2)->for($domain)->create([
        'action' => RequestAction::Blocked, 'country_code' => 'BR', 'occurred_at' => now()->subMinutes(5),
    ]);
    EdgeRequest::factory()->for($domain)->create([
        'action' => RequestAction::Diverted, 'country_code' => 'BR', 'occurred_at' => now()->subMinutes(5),
    ]);

    $data = app(SummarizeTrafficMap::class)->handle($team, MapDimension::Country, TrafficPeriod::Day);

    expect($data->requests)->toBe(6)
        ->and($data->suspicious)->toBe(3)
        ->and($data->suspectPct())->toBe(50)
        ->and($data->groups)->toBe(2);

    $tiles = tilesByKey($data->tiles);

    expect($tiles['cUS']->requests)->toBe(3)
        ->and($tiles['cUS']->suspicious)->toBe(0)
        ->and($tiles['cBR']->requests)->toBe(3)
        ->and($tiles['cBR']->suspicious)->toBe(3)
        ->and($tiles['cBR']->suspectPct())->toBe(100);

    // Sized by volume: every tile has a non-zero rectangle that stays within the box.
    foreach ($data->tiles as $tile) {
        expect($tile->w)->toBeGreaterThan(0.0)
            ->and($tile->x + $tile->w)->toBeLessThanOrEqual(100.01);
    }
});

test('it groups by domain and leaves out other teams', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $busy = Domain::factory()->for($team)->verified()->create(['hostname' => 'busy.example.com']);
    $quiet = Domain::factory()->for($team)->verified()->create(['hostname' => 'quiet.example.com']);

    EdgeRequest::factory()->count(5)->for($busy)->create(['occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->for($quiet)->create(['occurred_at' => now()->subMinute()]);

    // Another team's traffic must not appear.
    $other = Domain::factory()->for(Team::factory())->verified()->create();
    EdgeRequest::factory()->count(9)->for($other)->create(['occurred_at' => now()->subMinute()]);

    $data = app(SummarizeTrafficMap::class)->handle($team, MapDimension::Domain, TrafficPeriod::Day);

    expect($data->requests)->toBe(6)->and($data->groups)->toBe(2);

    $tiles = tilesByKey($data->tiles);

    expect($tiles['d'.$busy->id]->requests)->toBe(5)
        ->and($tiles['d'.$busy->id]->sym)->toBe('BUSY')
        ->and($tiles['d'.$quiet->id]->requests)->toBe(1);
});

test('it groups by client IP', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();

    EdgeRequest::factory()->count(4)->for($domain)->create(['client_ip' => '203.0.113.7', 'country_code' => 'BR', 'occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.9', 'country_code' => 'US', 'occurred_at' => now()->subMinute()]);

    $data = app(SummarizeTrafficMap::class)->handle($user->currentTeam, MapDimension::Ip, TrafficPeriod::Day);

    $tiles = tilesByKey($data->tiles);

    // Each IP tile carries its country flag, with the address still in the label.
    expect($data->groups)->toBe(2)
        ->and($tiles['ip203.0.113.7']->requests)->toBe(4)
        ->and($tiles['ip203.0.113.7']->sym)->toContain('203.0.113.7')
        ->and($tiles['ip203.0.113.7']->sym)->toContain('🇧🇷')
        ->and($tiles['ip203.0.113.7']->sub)->toBe('Brazil');
});

test('an Activity search that filters on a domain field does not error and scopes the map', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $wanted = Domain::factory()->for($team)->verified()->create(['hostname' => 'wanted.example']);
    $other = Domain::factory()->for($team)->verified()->create(['hostname' => 'other.example']);

    EdgeRequest::factory()->count(3)->for($wanted)->create(['occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->count(5)->for($other)->create(['occurred_at' => now()->subMinute()]);

    $where = (new RequestQuery("host = 'wanted.example'"))->parse()['where'];

    $data = app(SummarizeTrafficMap::class)->handle($team, MapDimension::Domain, TrafficPeriod::Day, null, $where);

    expect($data->requests)->toBe(3)
        ->and($data->groups)->toBe(1)
        ->and(tilesByKey($data->tiles)['d'.$wanted->id]->requests)->toBe(3);
});

test('a secondary dimension subdivides the big blocks and leaves small ones solid', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $big = Domain::factory()->for($team)->verified()->create(['hostname' => 'big.example.com']);
    $small = Domain::factory()->for($team)->verified()->create(['hostname' => 'small.example.com']);

    // big.example.com has two countries → it should be split; small has one → solid.
    EdgeRequest::factory()->count(6)->for($big)->create(['country_code' => 'US', 'occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->count(4)->for($big)->create(['country_code' => 'BR', 'occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->count(5)->for($small)->create(['country_code' => 'US', 'occurred_at' => now()->subMinute()]);

    $data = app(SummarizeTrafficMap::class)->handle(
        $team, MapDimension::Domain, TrafficPeriod::Day, secondary: MapDimension::Country,
    );

    expect($data->twoLevel())->toBeTrue()
        ->and($data->secondary)->toBe(MapDimension::Country)
        ->and($data->requests)->toBe(15)
        ->and($data->blocks)->toHaveCount(2);

    $blocks = collect($data->blocks)->keyBy('key');

    expect($blocks['d'.$big->id]->subdivided)->toBeTrue()
        ->and($blocks['d'.$big->id]->tiles)->toHaveCount(2)
        ->and($blocks['d'.$small->id]->subdivided)->toBeFalse()
        ->and($blocks['d'.$small->id]->tiles)->toHaveCount(1);

    // Leaves are laid out in the block's own body (a 0..100 box), so the header never overlaps them.
    foreach ($blocks['d'.$big->id]->tiles as $leaf) {
        expect($leaf->x)->toBeGreaterThanOrEqual(-0.01)
            ->and($leaf->x + $leaf->w)->toBeLessThanOrEqual(100.01);
    }
});

test('the same dimension as primary and secondary falls back to one level', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();
    EdgeRequest::factory()->count(3)->for($domain)->create(['occurred_at' => now()->subMinute()]);

    $data = app(SummarizeTrafficMap::class)->handle(
        $user->currentTeam, MapDimension::Domain, TrafficPeriod::Day, secondary: MapDimension::Domain,
    );

    expect($data->twoLevel())->toBeFalse()->and($data->tiles)->not->toBeEmpty();
});

test('it only counts requests inside the period', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();

    EdgeRequest::factory()->count(2)->for($domain)->create(['occurred_at' => now()->subMinutes(30)]);
    EdgeRequest::factory()->count(4)->for($domain)->create(['occurred_at' => now()->subHours(5)]);

    $data = app(SummarizeTrafficMap::class)->handle($user->currentTeam, MapDimension::Domain, TrafficPeriod::Hour);

    expect($data->requests)->toBe(2);
});

test('the page renders the map for a member', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();
    EdgeRequest::factory()->count(3)->for($domain)->create(['country_code' => 'US', 'occurred_at' => now()->subMinute()]);

    $this->actingAs($user);

    Livewire::test('traffic-map::index')
        ->assertOk()
        ->assertSee('Traffic Map')
        ->assertSeeHtml('data-test="traffic-map"')
        ->set('dimension', 'domain')
        ->assertSeeHtml('data-test="traffic-map"');
});

test('going live starts a live tail and stopping ends it', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user);

    $page = Livewire::test('traffic-map::index');

    $page->call('toggleLive')->assertSet('live', true);
    expect($user->currentTeam->liveTails()->where('user_id', $user->id)->exists())->toBeTrue();

    $page->call('toggleLive')->assertSet('live', false);
    expect($user->currentTeam->liveTails()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('Activity offers the Map view and renders it', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();
    EdgeRequest::factory()->count(4)->for($domain)->create(['country_code' => 'US', 'occurred_at' => now()->subMinute()]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="view-map"')
        ->set('view', 'map')
        ->assertSeeHtml('data-test="activity-traffic-map"')
        ->assertSeeHtml('data-test="traffic-map"');
});
