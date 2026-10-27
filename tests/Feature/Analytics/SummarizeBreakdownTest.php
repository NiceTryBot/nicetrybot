<?php

use App\Actions\Analytics\SummarizeBreakdown;
use App\Enums\NetworkKind;
use App\Enums\RequestAction;
use App\Enums\TrafficBreakdown;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;

test('networks are ranked busiest first with suspicious requests, unique IPs and hosting flagged', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->count(2)->sequence(['client_ip' => '198.51.100.148'], ['client_ip' => '198.51.100.82'])->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->probe()->create(['client_ip' => '198.51.100.82']);
    EdgeRequest::factory()->for($domain)->fromNetwork(7922, 'Comcast Cable Communications', NetworkKind::Unknown)->create();

    $summary = app(SummarizeBreakdown::class)->handle($user->currentTeam, TrafficBreakdown::Networks, TrafficPeriod::Day);

    [$ovh, $comcast] = $summary->rows;

    expect($summary->total)->toBe(4)
        ->and($ovh->label)->toBe('OVH SAS')
        ->and($ovh->detail)->toBe('AS16276')
        ->and($ovh->requests)->toBe(3)
        ->and($ovh->suspicious)->toBe(1)
        ->and($ovh->uniqueIps)->toBe(2)
        ->and($ovh->isHosting)->toBeTrue()
        ->and($comcast->label)->toBe('Comcast Cable Communications')
        ->and($comcast->isHosting)->toBeFalse();
});

test('countries are labelled by name', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->locatedIn('CA', 'Quebec', 'Montreal')->create();

    $summary = app(SummarizeBreakdown::class)->handle($user->currentTeam, TrafficBreakdown::Countries, TrafficPeriod::Day);

    expect($summary->rows[0]->label)->toBe('Canada')
        ->and($summary->rows[0]->key)->toBe('CA');
});

test('a fingerprint counts how many user agents it claimed to be', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $script = 't13d131100_f57a46bbacb6_ab7e3b40a677';
    EdgeRequest::factory()->for($domain)->withFingerprint($script)->create(['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/124.0.0.0']);
    EdgeRequest::factory()->for($domain)->withFingerprint($script)->create(['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/131.0.0.0']);

    $summary = app(SummarizeBreakdown::class)->handle($user->currentTeam, TrafficBreakdown::Fingerprints, TrafficPeriod::Day);

    expect($summary->rows[0]->label)->toBe($script)
        ->and($summary->rows[0]->related)->toBe(2)
        ->and($summary->rows[0]->detail)->toContain('offers no ALPN');
});

test('only the team\'s requests in the period are counted', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork()->create();
    EdgeRequest::factory()->for($domain)->fromNetwork()->create(['occurred_at' => now()->subDays(3)]);
    EdgeRequest::factory()->fromNetwork()->create();

    $summary = app(SummarizeBreakdown::class)->handle($user->currentTeam, TrafficBreakdown::Networks, TrafficPeriod::Day);

    expect($summary->total)->toBe(1)
        ->and($summary->rows[0]->requests)->toBe(1);
});

test('the analytics page links each network to its filtered activity', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(14061, 'DigitalOcean, LLC')->create(['action' => RequestAction::Observed]);

    $this->actingAs($user)
        ->get(route('analytics.index', ['period' => '24h']))
        ->assertOk()
        ->assertSee('DigitalOcean, LLC')
        ->assertSee('asn=14061', escape: false)
        ->assertSee('IP geolocation by DB-IP');
});

test('path categories show their traffic, how many paths they group, and the rule they would become', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env.local']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/contact']);

    $summary = app(SummarizeBreakdown::class)->handle($user->currentTeam, TrafficBreakdown::Categories, TrafficPeriod::Day);

    $creds = collect($summary->rows)->firstWhere('key', 'probe/creds');
    $uncategorized = collect($summary->rows)->firstWhere('key', null);

    expect($creds->label)->toBe('probe/creds')
        ->and($creds->requests)->toBe(2)
        ->and($creds->related)->toBe(2)
        ->and($creds->patterns)->toContain('*/.env*')
        ->and($creds->rule)->toStartWith('@probe_creds path_regexp probe_creds')
        ->and(TrafficBreakdown::Categories->activityFilter($creds))->toBe(['category' => 'probe/creds'])
        ->and($uncategorized->label)->toBe('Uncategorized')
        ->and(TrafficBreakdown::Categories->activityFilter($uncategorized))->toBeNull();
});
