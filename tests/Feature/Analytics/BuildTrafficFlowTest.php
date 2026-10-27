<?php

use App\Actions\Analytics\BuildTrafficFlow;
use App\Enums\RequestAction;
use App\Enums\TrafficFlow;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;

test('the identity flow traces each network through its fingerprints to the user agents they claimed', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $script = 't13d131100_f57a46bbacb6_ab7e3b40a677';
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->withFingerprint($script)->count(3)->create(['user_agent' => 'Mozilla/5.0 Chrome/124.0.0.0']);
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->withFingerprint($script)->create(['user_agent' => 'Mozilla/5.0 Chrome/131.0.0.0']);

    $diagram = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Identity, TrafficPeriod::Day);

    $nodes = collect($diagram->nodes);

    expect($diagram->columns)->toBe(['Network', 'TLS fingerprint', 'User agent'])
        ->and($nodes->firstWhere('label', 'OVH SAS')['value'])->toBe(4)
        ->and($nodes->firstWhere('label', 'OVH SAS')['filter'])->toBe(['asn' => 16276])
        ->and($nodes->firstWhere('label', $script)['filter'])->toBe(['ja4' => $script])
        ->and($nodes->where('column', 2)->pluck('value')->sort()->values()->all())->toBe([1, 3]);
});

test('outcomes show what the edge did with tagged requests and the status class of the rest', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->probe(RequestAction::Observed)->create();
    EdgeRequest::factory()->for($domain)->create(['status_code' => 429]);
    EdgeRequest::factory()->for($domain)->create(['status_code' => 200]);

    $diagram = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Origins, TrafficPeriod::Day);

    expect(collect($diagram->nodes)->where('column', 2)->pluck('label')->sort()->values()->all())
        ->toBe(['2xx Success', '429 Rate limited', 'Observed']);
});

test('journeys group a visitor\'s requests into visits split by 30 quiet minutes', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $visit = fn (string $path, int $minutesAgo) => EdgeRequest::factory()->for($domain)->withFingerprint()->create([
        'client_ip' => '203.0.113.7',
        'path' => $path,
        'occurred_at' => now()->subMinutes($minutesAgo),
    ]);
    $visit('/', 200);
    $visit('/', 199);
    $visit('/claim', 195);
    $visit('/', 60);

    $diagram = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Journeys, TrafficPeriod::Day);

    expect(collect($diagram->paths)->map(fn (array $path) => [implode(' → ', $path['labels']), $path['value']])->sort()->values()->all())
        ->toBe([['/', 1], ['/ → /claim', 1]]);
});

test('only the team\'s requests in the period flow through the diagram', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork()->create();
    EdgeRequest::factory()->for($domain)->fromNetwork()->create(['occurred_at' => now()->subDays(3)]);
    EdgeRequest::factory()->fromNetwork()->create();

    $diagram = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Identity, TrafficPeriod::Day);

    expect($diagram->total)->toBe(1);
});

test('the flows page links each node to its filtered activity', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(14061, 'DigitalOcean, LLC')->create();

    $this->actingAs($user)
        ->get(route('analytics.flows', ['period' => '24h']))
        ->assertOk()
        ->assertSee('DigitalOcean, LLC')
        ->assertSee('asn=14061', escape: false)
        ->assertSee('Show as table');
});

test('paths matching a category pattern are grouped into one category node', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    foreach (['/.env', '/.env.local', '/.env.prod', '/contact'] as $path) {
        EdgeRequest::factory()->for($domain)->withFingerprint()->create(['path' => $path]);
    }

    $grouped = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Requests, TrafficPeriod::Day);
    $exact = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Requests, TrafficPeriod::Day, groupPaths: false);

    $creds = collect($grouped->nodes)->firstWhere('label', 'probe/creds');

    expect(collect($grouped->nodes)->where('column', 2)->pluck('label')->sort()->values()->all())->toBe(['/contact', 'probe/creds'])
        ->and($creds['value'])->toBe(3)
        ->and($creds['filter'])->toBe(['category' => 'probe/creds'])
        ->and($creds['detail'])->toContain('3 paths: /.env, /.env.local, /.env.prod')
        ->and(collect($exact->nodes)->where('column', 2)->pluck('label')->sort()->values()->all())->toBe(['/.env', '/.env.local', '/.env.prod', '/contact']);
});

test('a journey through several paths of one category counts as one step', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    foreach (['/' => 10, '/.env' => 9, '/.env.local' => 8, '/config.js' => 7] as $path => $minutesAgo) {
        EdgeRequest::factory()->for($domain)->withFingerprint()->create(['client_ip' => '203.0.113.7', 'path' => $path, 'occurred_at' => now()->subMinutes($minutesAgo)]);
    }

    $diagram = app(BuildTrafficFlow::class)->handle($user->currentTeam, TrafficFlow::Journeys, TrafficPeriod::Day);

    expect(collect($diagram->paths)->map(fn (array $path) => implode(' → ', $path['labels']))->all())
        ->toBe(['/ → probe/creds → probe/config']);
});
