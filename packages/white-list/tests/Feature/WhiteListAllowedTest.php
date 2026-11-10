<?php

use App\Addons\Addons;
use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\Verdict;
use App\Support\AllowedAddresses;
use Illuminate\Support\Facades\Cache;
use NiceTry\Blackhole\Support\BlackholeGuard;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\WhiteList\Models\WhitelistEntry;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Cache::flush();
});

/**
 * Resolve the allow list afresh, after the entries and switches in a test have been set up.
 */
function freshAllowed(): AllowedAddresses
{
    Cache::flush();
    app(AddonSwitchboard::class)->forget();
    app()->forgetScopedInstances();

    return app(AllowedAddresses::class);
}

test('an enabled entry is allowed, a disabled one is not', function () {
    WhitelistEntry::factory()->ranges(['66.249.64.0/27'])->create();
    WhitelistEntry::factory()->disabled()->ranges(['198.51.100.0/24'])->create();

    $allowed = freshAllowed();

    expect($allowed->allows('66.249.64.10'))->toBeTrue()
        ->and($allowed->allows('198.51.100.10'))->toBeFalse()
        ->and($allowed->ranges())->toBe(['66.249.64.0/27']);
});

test('the edge is told the enabled entries\' ranges', function () {
    WhitelistEntry::factory()->ranges(['66.249.64.0/27'])->create();
    WhitelistEntry::factory()->disabled()->ranges(['198.51.100.0/24'])->create();

    $this->withToken('edge-token')->getJson(route('edge.allowed'))
        ->assertOk()
        ->assertExactJson(['allow' => ['66.249.64.0/27']]);
});

test('turning the add-on off drops its ranges from the allow list', function () {
    WhitelistEntry::factory()->ranges(['66.249.64.0/27'])->create();
    AddonSwitch::factory()->create(['slug' => 'white-list']);

    expect(freshAllowed()->ranges())->toBe([])
        ->and(freshAllowed()->allows('66.249.64.10'))->toBeFalse();

    $this->withToken('edge-token')->getJson(route('edge.allowed'))->assertExactJson(['allow' => []]);
});

test('an allowed address is waved past the gym it would otherwise train in', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    $rule = EdgeRule::factory()->for($team)->create([
        'source' => "TAG ip, ja4 AS 'non-us' FOR 1 DAY\nWHERE host = 'sign.example' AND country != 'US'",
    ]);
    GymRoutine::factory()->for($team)
        ->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])
        ->obstacles(['waiting-room' => ['seconds' => 10, 'intensity' => 'light', 'show_time' => true, 'headline' => 'Spot me, bro']])
        ->create();

    $check = fn () => $this->withToken('edge-token')->withHeaders([
        'X-NiceTry-Host' => 'sign.example',
        'X-NiceTry-Uri' => '/phpmyadmin.php',
        'X-NiceTry-Client-Ip' => '203.0.113.188',
        'X-NiceTry-JA4' => 't13d1516h2_8daaf6152771_02713d6af862',
        'User-Agent' => 'Mozilla/5.0 Chrome/141.0',
        'X-NiceTry-Features' => 'gym-rules',
        'X-NiceTry-Rules' => (string) $rule->id,
        'X-NiceTry-Country' => 'AL',
        'X-NiceTry-ASN' => '42313',
    ])->get(route('edge.gym.check'));

    // Without an entry the client is taken into the gym and earns a verdict.
    $check()->assertForbidden()->assertSee('Spot me, bro');
    expect(Verdict::count())->toBe(1)->and(GymSession::count())->toBe(1);

    Verdict::query()->delete();
    GymSession::query()->delete();
    WhitelistEntry::factory()->ranges(['203.0.113.188'])->create(['category' => 'bot/crawler']);

    // A fresh request would see the new entry; the test shares a container, so stand in for that.
    Cache::flush();
    app()->forgetScopedInstances();

    // With it, the same request passes straight through, and no verdict or session is made.
    $check()->assertNoContent();
    expect(Verdict::count())->toBe(0)->and(GymSession::count())->toBe(0);
});

test('an allowed verdict-holder is left out of the tarpit feed', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $rule = EdgeRule::factory()->for($team)->script('BLOCK ip FOR 1 HOUR WHERE status = 404 WITHIN 10 MINUTES HAVING COUNT(*) >= 5')->create();
    Verdict::factory()->create(['team_id' => $team->id, 'edge_rule_id' => $rule->id, 'client_ip' => '203.0.113.20']);
    Verdict::factory()->create(['team_id' => $team->id, 'edge_rule_id' => $rule->id, 'client_ip' => '203.0.113.21']);
    $tarpit = Tarpit::factory()->for($team)->create();
    $tarpit->rules()->attach($rule);

    WhitelistEntry::factory()->ranges(['203.0.113.21'])->create(['category' => 'partner/scanner']);
    Cache::flush();

    $clients = $this->withToken('edge-token')->getJson(route('edge.tarpits'))->assertOk()->json('tarpits.0.clients');

    expect(collect($clients)->pluck('ip')->all())->toBe(['203.0.113.20']);
});

test('the blackhole protects allowed ranges and refuses to list inside them', function () {
    WhitelistEntry::factory()->ranges(['66.249.64.0/27'])->create();
    freshAllowed();

    $guard = new BlackholeGuard;

    expect($guard->protectedAddresses())->toContain('66.249.64.0/27')
        ->and($guard->refusal('66.249.64.10'))->not->toBeNull();
})->skip(fn () => ! app(Addons::class)->has('blackhole'), 'Needs the paid Blackhole add-on (nicetry/blackhole).');
