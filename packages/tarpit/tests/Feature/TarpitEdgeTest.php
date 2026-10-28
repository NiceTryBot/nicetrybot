<?php

use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\Verdict;
use Illuminate\Support\Facades\Cache;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Cache::flush();
});

test('the edge hears each enabled tarpit with its hosts in Enforce mode, its waits, and who is in it', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    Domain::factory()->for($team)->verified()->create(['hostname' => 'observed.example']);

    $live = EdgeRule::factory()->for($team)->script("TAG ip AS 'prober' FOR 1 DAY WHERE category = 'probe'")->create();
    $counting = EdgeRule::factory()->for($team)->script('BAN ip FOR 1 HOUR WHERE status = 404 WITHIN 10 MINUTES HAVING COUNT(*) >= 5')->create();
    Verdict::factory()->create(['team_id' => $team->id, 'edge_rule_id' => $counting->id, 'client_ip' => '203.0.113.20']);
    Verdict::factory()->expired()->create(['team_id' => $team->id, 'edge_rule_id' => $counting->id, 'client_ip' => '203.0.113.21']);
    Verdict::factory()->create(['team_id' => $team->id, 'client_ip' => '203.0.113.22']);

    $tarpit = Tarpit::factory()->for($team)->create([
        'free_tries' => 1,
        'delays' => [2, 8, 24],
        'max_delay_seconds' => 30,
        'max_held' => 50,
        'when_full' => TarpitWhenFull::HangUp,
        'after_wait' => TarpitAfterWait::NotFound,
        'forget_after_seconds' => 600,
        'not_found_count' => 5,
        'not_found_within_seconds' => 60,
    ]);
    $tarpit->rules()->attach([$live->id, $counting->id]);
    Tarpit::factory()->for($team)->disabled()->create();

    $this->getJson(route('edge.tarpits'))->assertUnauthorized();

    $response = $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk();

    expect($response->json('tarpits'))->toHaveCount(1)
        ->and($response->json('tarpits.0'))->toMatchArray([
            'id' => $tarpit->id,
            'free' => 1,
            'delays' => [2, 8, 24],
            'cap' => 30,
            'max_held' => 50,
            'when_full' => 'hangup',
            'after' => 'not_found',
            'forget' => 600,
            'clients' => [['ip' => '203.0.113.20', 'ja4' => null, 'why' => 'verdict']],
            'not_found' => ['count' => 5, 'within' => 60],
            'burst' => null,
        ])
        ->and($response->json('tarpits.0.hosts'))->toContain('shop.example')->not->toContain('observed.example')
        ->and($response->json('tarpits.0.rules'))->toHaveCount(1)
        ->and($response->json('tarpits.0.rules.0.id'))->toBe($live->id)
        ->and($response->json('categories'))->not->toBeEmpty()
        ->and($response->json('domains.0.hostname'))->toBe('shop.example');
});

test('a tarpit taking no-shows lists clients that left the gym without running its script', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    Tarpit::factory()->for($team)->takingGymNoShows()->create();

    $gaveUpAt = now()->subHour();
    GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.1', 'started_at' => $gaveUpAt, 'last_seen_at' => $gaveUpAt->addSecond()]);
    GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.2', 'started_at' => $gaveUpAt, 'last_seen_at' => $gaveUpAt->addMinutes(3)]);
    GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.3', 'started_at' => $gaveUpAt, 'last_seen_at' => $gaveUpAt, 'hashes' => 400]);
    GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.4', 'started_at' => now()->subMinute(), 'last_seen_at' => now()->subMinute()]);
    GymSession::factory()->for($team)->cleared()->create(['client_ip' => '198.51.100.5', 'started_at' => $gaveUpAt, 'last_seen_at' => $gaveUpAt]);
    GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.6', 'started_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2)]);

    $response = $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk();

    expect($response->json('tarpits.0.clients'))->toBe([['ip' => '198.51.100.1', 'ja4' => null, 'why' => 'no_show']]);
});

test('a tarpit with no domain in Enforce mode, or with the add-on off, holds no one', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'observed.example']);
    Tarpit::factory()->for($team)->create(['burst_count' => 30]);

    $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk()->assertExactJson(['tarpits' => []]);

    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    Cache::flush();
    $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk()->assertJsonCount(1, 'tarpits');

    AddonSwitch::factory()->create(['slug' => 'tarpit']);
    app(AddonSwitchboard::class)->forget();
    $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk()->assertExactJson(['tarpits' => []]);
});

test('a tarpit naming its domains covers only those', function () {
    $team = Team::factory()->create();
    $shop = Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'blog.example']);
    $tarpit = Tarpit::factory()->for($team)->create(['all_domains' => false, 'burst_count' => 30]);
    $tarpit->domains()->attach($shop);

    $hosts = $this->withToken('edge-token')->getJson(route('edge.tarpits'))->json('tarpits.0.hosts');

    expect($hosts)->toContain('shop.example')->not->toContain('blog.example');
});
