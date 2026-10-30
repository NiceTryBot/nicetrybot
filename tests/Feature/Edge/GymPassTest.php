<?php

use App\Enums\TlsFingerprintType;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\Verdict;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

/**
 * Ask the gym check about a request to /login carrying a pass, from one client.
 */
function checkWithPass(string $host, string $pass, string $ip = '203.0.113.7', string $ja4 = 't13d1516h2_8daaf6152771_02713d6af862')
{
    return test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => $host, 'X-NiceTry-Uri' => '/login', 'X-NiceTry-Client-Ip' => $ip, 'X-NiceTry-JA4' => $ja4])
        ->withUnencryptedCookies([GymSession::PASS_COOKIE => $pass])
        ->get(route('edge.gym.check'));
}

/**
 * A team enforcing on two sites, with a routine that sends /login to the gym, and a pass a visitor
 * earned on that routine at sign.example.
 *
 * @return array{0: Team, 1: GymRoutine, 2: string}
 */
function passEarnedAtLogin(): array
{
    $team = Team::factory()->create();
    $site = Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $routine = GymRoutine::factory()->for($team)->entering([['field' => 'path', 'operator' => 'starts_with', 'values' => ['/login']]])->create();
    $session = GymSession::factory()->create(['team_id' => $team->id, 'domain_id' => $site->id]);
    $session->routines()->attach($routine);

    return [$team, $routine, $session->passCookie(3600, secure: true)->getValue()];
}

test('a pass works on the site it was earned on, not on the team\'s other sites', function () {
    [, , $pass] = passEarnedAtLogin();

    checkWithPass('sign.example', $pass)->assertNoContent();
    checkWithPass('shop.example', $pass)->assertOk();
});

test('a pass from one routine doesn\'t get anyone past a stricter routine they never cleared', function () {
    [$team, , $pass] = passEarnedAtLogin();
    GymRoutine::factory()->for($team)->entering([['field' => 'ip', 'operator' => 'in', 'values' => ['198.51.100.0/24']]])->create();

    checkWithPass('sign.example', $pass)->assertNoContent();
    checkWithPass('sign.example', $pass, ip: '198.51.100.9')->assertOk();
});

test('a pass handed around more networks than one person uses stops working', function () {
    [, , $pass] = passEarnedAtLogin();

    foreach (['203.0.113.7', '203.0.113.99', '198.51.100.4', '192.0.2.10', '2001:db8:1::1'] as $ip) {
        checkWithPass('sign.example', $pass, ip: $ip)->assertNoContent();
    }

    checkWithPass('sign.example', $pass, ip: '100.1.2.3')->assertOk();
    checkWithPass('sign.example', $pass)->assertOk();
});

test('a client carrying someone else\'s pass is still judged by its own fingerprint', function () {
    [$team, , $pass] = passEarnedAtLogin();
    $rule = EdgeRule::factory()->for($team)->script("BAN ja4 FOR 1 DAY WHERE path = '/login'")->create();
    $farm = TlsFingerprint::create(['type' => TlsFingerprintType::Ja4, 'value' => 't13d311000_e8f1e7e78f70_d339722ba4af']);
    Verdict::factory()->for($rule, 'rule')->create(['team_id' => $team->id, 'client_ip' => null, 'ja4_id' => $farm->id]);
    GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create();

    checkWithPass('sign.example', $pass)->assertNoContent();
    checkWithPass('sign.example', $pass, ja4: $farm->value)->assertOk();
});
