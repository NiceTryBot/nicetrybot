<?php

use App\Data\LiveRequest;
use App\Data\RuleScript;
use App\Enums\GymPassState;
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
 * A team enforcing on sign.example whose failed-login rule has tagged 203.0.113.7, a one-try routine
 * for tagged clients, and that client's cleared session with its pass cookie.
 *
 * @return array{session: GymSession, pass: string, verdict: Verdict}
 */
function taggedClientWithPass(bool $oneTry = true): array
{
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    $rule = EdgeRule::factory()->for($team)->script("TAG ip AS 'failed-login' FOR 1 DAY WHERE path = '/login' UNTIL method = 'GET' AND path = '/dashboard' AND status = 200")->create();
    $verdict = Verdict::factory()->for($rule, 'rule')->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7', 'ja4_id' => null]);
    $routine = GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['one_try' => $oneTry]);
    $session = GymSession::factory()->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7']);
    $session->routines()->attach($routine);

    return ['session' => $session, 'pass' => $session->passCookie(3600, secure: true)->getValue(), 'verdict' => $verdict];
}

/**
 * Ask the gym check (or report) about one request from the tagged client.
 *
 * @param  array<string, string>  $headers
 */
function edgeAsks(string $route, string $method, string $uri, string $pass, array $headers = [])
{
    return test()->flushHeaders()
        ->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => 'sign.example', 'X-NiceTry-Method' => $method, 'X-NiceTry-Uri' => $uri, 'X-NiceTry-Client-Ip' => '203.0.113.7', ...$headers])
        ->withUnencryptedCookies([GymSession::PASS_COOKIE => $pass])
        ->get(route($route));
}

test('a one-try pass allows page loads and one submission, then sends the visitor back to the gym', function () {
    ['session' => $session, 'pass' => $pass] = taggedClientWithPass();

    edgeAsks('edge.gym.check', 'GET', '/login', $pass)->assertNoContent();
    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', $pass, ['X-Livewire' => 'true'])->assertNoContent();

    expect($session->fresh()->pass_state)->toBe(GymPassState::Spent);

    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', $pass, ['X-Livewire' => 'true', 'Referer' => 'https://sign.example/login'])
        ->assertExactJson(['effects' => ['html' => null, 'dirty' => [], 'redirect' => '/login'], 'serverMemo' => []]);
    edgeAsks('edge.gym.check', 'GET', '/login', $pass)->assertOk()->assertCookie(GymSession::SESSION_COOKIE, encrypted: false);
});

test('after the try, the page that could lift the verdict opens provisionally and the edge reports how it went', function (int $status, bool $lifted) {
    ['session' => $session, 'pass' => $pass, 'verdict' => $verdict] = taggedClientWithPass();
    $session->update(['pass_state' => GymPassState::Spent]);

    edgeAsks('edge.gym.check', 'GET', '/dashboard', $pass)->assertNoContent()->assertHeader('X-NiceTry-Report', '1');

    expect($session->fresh()->pass_state)->toBe(GymPassState::Provisional);

    edgeAsks('edge.gym.report', 'GET', '/dashboard', $pass, ['X-NiceTry-Status' => (string) $status])->assertNoContent();

    expect($verdict->fresh()->isActive())->toBe(! $lifted)
        ->and($session->fresh()->pass_state)->toBe($lifted ? null : GymPassState::Spent);
})->with([
    'signed in: the dashboard answers 200' => [200, true],
    'not signed in: bounced back to the login page' => [302, false],
]);

test('once the verdict is lifted the visitor goes straight through, pass or not', function () {
    ['session' => $session, 'pass' => $pass, 'verdict' => $verdict] = taggedClientWithPass();
    $session->update(['pass_state' => GymPassState::Spent]);
    $verdict->update(['lifted_at' => now()]);

    edgeAsks('edge.gym.check', 'POST', '/livewire/message/widget', $pass, ['X-Livewire' => 'true'])->assertNoContent();
});

test('a spent pass doesn\'t open pages that can\'t lift the verdict', function () {
    ['session' => $session, 'pass' => $pass] = taggedClientWithPass();
    $session->update(['pass_state' => GymPassState::Spent]);

    edgeAsks('edge.gym.check', 'GET', '/pricing', $pass)->assertOk()->assertSee('<html', escape: false);

    expect($session->fresh()->pass_state)->toBe(GymPassState::Spent);
});

test('a time pass still covers every request until it expires', function () {
    ['session' => $session, 'pass' => $pass] = taggedClientWithPass(oneTry: false);

    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', $pass, ['X-Livewire' => 'true'])->assertNoContent();
    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', $pass, ['X-Livewire' => 'true'])->assertNoContent();

    expect($session->fresh()->pass_state)->toBeNull();
});

test('a live request answers a rule expression, or says it can\'t know yet', function (?int $status, ?bool $expected) {
    $until = RuleScript::parse("TAG ip AS 'x' FOR 1 DAY UNTIL method = 'GET' AND path = '/dashboard' AND status = 200")->until;

    expect((new LiveRequest('GET', 'sign.example', '/dashboard', $status))->evaluate($until))->toBe($expected)
        ->and((new LiveRequest('GET', 'sign.example', '/pricing', $status))->evaluate($until))->toBeFalse();
})->with([
    'before the origin answers' => [null, null],
    'signed in' => [200, true],
    'redirected' => [302, false],
]);

test('a browser whose TLS fingerprint changes keeps its verdict through its gym cookie', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    $rule = EdgeRule::factory()->for($team)->script("TAG ip, ja4 AS 'failed-login' FOR 1 DAY WHERE path = '/login'")->create();
    $entered = TlsFingerprint::create(['type' => TlsFingerprintType::Ja4, 'value' => 't13d1517h2_8daaf6152771_cb7bf5808d99']);
    Verdict::factory()->for($rule, 'rule')->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7', 'ja4_id' => $entered->id]);
    $routine = GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['one_try' => true]);
    $session = GymSession::factory()->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7', 'ja4' => $entered->value, 'pass_state' => GymPassState::Spent]);
    $session->routines()->attach($routine);
    $pass = $session->passCookie(3600, secure: true)->getValue();
    $resumed = ['X-NiceTry-JA4' => 't13d1518h2_8daaf6152771_e2d80978ab2e', 'X-Livewire' => 'true'];

    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', $pass, $resumed)->assertOk()->assertJsonPath('effects.redirect', '/');
    edgeAsks('edge.gym.check', 'POST', '/livewire/message/login', '', $resumed)->assertNoContent();
});
