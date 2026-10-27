<?php

use App\Data\GymEntry;
use App\Enums\TlsFingerprintType;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\Verdict;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

/**
 * Ask the gym check about one request, the way the edge does.
 *
 * @param  array<string, string>  $cookies
 */
function gymCheck(string $host, string $uri, array $cookies = [])
{
    return test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => $host, 'X-NiceTry-Uri' => $uri, 'X-NiceTry-Client-Ip' => '203.0.113.7', 'X-NiceTry-JA4' => 't13d1516h2_8daaf6152771_02713d6af862', 'User-Agent' => 'curl/8.7.1'])
        ->withUnencryptedCookies($cookies)
        ->get(route('edge.gym.check'));
}

/**
 * A team enforcing on sign.example with a routine that sends /gymbros to a 10-second waiting room.
 */
function gymbrosRoutine(): GymRoutine
{
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);

    return GymRoutine::factory()->for($team)
        ->entering([['field' => 'path', 'operator' => 'starts_with', 'values' => ['/gymbros']]])
        ->obstacles(['waiting-room' => ['seconds' => 10, 'intensity' => 'light', 'show_time' => true, 'headline' => 'Spot me, bro']])
        ->create();
}

test('the edge only hears about the paths a routine pins down, on enforcing domains', function () {
    $routine = gymbrosRoutine();
    $everyone = GymRoutine::factory()->for($routine->team)->entering([['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']]])->create();
    $observing = Team::factory()->create();
    Domain::factory()->for($observing)->verified()->create(['hostname' => 'observe.example']);
    GymRoutine::factory()->for($observing)->create();

    $this->withToken('edge-token')
        ->getJson(route('edge.gym.triggers'))
        ->assertOk()
        ->assertExactJson(['triggers' => [
            ['id' => $routine->id, 'hosts' => ['sign-example.preview.example', 'sign.example'], 'method' => null, 'path' => '/gymbros*', 'clients' => null],
            ['id' => $everyone->id, 'hosts' => ['sign-example.preview.example', 'sign.example'], 'method' => null, 'path' => '*', 'clients' => null],
        ]]);
});

test('the edge must send its token to ask about the gym', function () {
    $this->getJson(route('edge.gym.check'))->assertUnauthorized();
    $this->getJson(route('edge.gym.triggers'))->assertUnauthorized();
});

test('a matching visitor gets the gym in place of the page, and a reload carries on the same session', function () {
    $routine = gymbrosRoutine();

    $first = gymCheck('sign.example', '/gymbros?ref=x')
        ->assertOk()
        ->assertSee('Spot me, bro')
        ->assertDontSee('NiceTry')
        ->assertDontSee('_nicetry', escape: false)
        ->assertCookie(GymSession::SESSION_COOKIE, encrypted: false);

    $session = GymSession::sole();
    $config = json_decode((string) json_decode('"'.Str::betweenFirst($first->getContent(), "JSON.parse('", "')").'"'), true);
    $attemptUrl = $config['attemptUrl'] ?? null;

    expect($attemptUrl)->toBe('/_q/'.$session->token)
        ->and($session)
        ->team_id->toBe($routine->team_id)
        ->destination->toBe('/gymbros?ref=x')
        ->client_ip->toBe('203.0.113.7')
        ->ja4->toBe('t13d1516h2_8daaf6152771_02713d6af862')
        ->user_agent->toBe('curl/8.7.1');

    gymCheck('sign.example', '/gymbros?ref=x', [GymSession::SESSION_COOKIE => $first->getCookie(GymSession::SESSION_COOKIE, decrypt: false)->getValue()])
        ->assertOk();

    expect(GymSession::count())->toBe(1);
});

test('a visitor on the domain\'s preview gets the gym too', function () {
    gymbrosRoutine();

    gymCheck('sign-example.preview.example', '/gymbros')
        ->assertOk()
        ->assertSee('Spot me, bro');
});

test('requests the routine doesn\'t want, and domains in observe mode, go straight through', function () {
    gymbrosRoutine();
    Domain::factory()->verified()->create(['hostname' => 'observe.example']);

    gymCheck('sign.example', '/pricing')->assertNoContent();
    gymCheck('observe.example', '/gymbros')->assertNoContent();
    gymCheck('unknown.example', '/gymbros')->assertNoContent();

    expect(GymSession::count())->toBe(0);
});

test('a valid pass skips the gym, and a forged, expired or other team\'s pass does not', function () {
    $routine = gymbrosRoutine();
    $session = GymSession::factory()->create(['team_id' => $routine->team_id]);
    $pass = $session->passCookie(3600, secure: true)->getValue();
    [$team, $expires, $token] = explode('.', $pass);
    $otherTeams = GymSession::factory()->create()->passCookie(3600, secure: true)->getValue();

    gymCheck('sign.example', '/gymbros', [GymSession::PASS_COOKIE => $pass])->assertNoContent();

    gymCheck('sign.example', '/gymbros', [GymSession::PASS_COOKIE => implode('.', [$team, $expires + 86400, $token, Str::afterLast($pass, '.')])])->assertOk();
    gymCheck('sign.example', '/gymbros', [GymSession::PASS_COOKIE => $otherTeams])->assertOk();

    $this->travel(2)->hours();
    gymCheck('sign.example', '/gymbros', [GymSession::PASS_COOKIE => $pass])->assertOk();
});

test('the page the visitor returns to always stays on the site', function () {
    gymbrosRoutine();

    gymCheck('sign.example', '//evil.example/gymbros')->assertNoContent();
    gymCheck('sign.example', '/gymbros//evil.example')->assertOk();

    expect(GymSession::sole()->destination)->toBe('/gymbros//evil.example');
});

test('a routine narrows the edge to paths only when its entry pins them down', function (array $conditions, bool $matchAll, ?array $globs) {
    expect(GymEntry::fromArray($conditions, $matchAll)->edgePathGlobs())->toBe($globs);
})->with([
    'starts with' => [[['field' => 'path', 'operator' => 'starts_with', 'values' => ['/GymBros', '/lift']]], true, ['/gymbros*', '/lift*']],
    'contains, with another condition' => [[['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']], ['field' => 'path', 'operator' => 'contains', 'values' => ['login']]], true, ['*login*']],
    'any of several paths' => [[['field' => 'path', 'operator' => 'starts_with', 'values' => ['/a']], ['field' => 'path', 'operator' => 'contains', 'values' => ['b']]], false, ['/a*', '*b*']],
    'any of a path or a network' => [[['field' => 'path', 'operator' => 'starts_with', 'values' => ['/a']], ['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']]], false, null],
    'not containing a path' => [[['field' => 'path', 'operator' => 'not_contains', 'values' => ['/a']]], true, null],
    'everyone' => [[], true, null],
]);

test('a routine for clients holding a rule\'s verdict sends the edge just those clients, or nothing', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    $rule = EdgeRule::factory()->for($team)->create();
    $routine = GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create();

    $this->withToken('edge-token')->getJson(route('edge.gym.triggers'))->assertExactJson(['triggers' => []]);

    $ja4 = TlsFingerprint::create(['type' => TlsFingerprintType::Ja4, 'value' => 'q13d0314h3_55b375c5d22e_79cc91d6b50c']);
    Verdict::factory()->for($rule, 'rule')->count(2)->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7', 'ja4_id' => $ja4->id]);
    Verdict::factory()->for($rule, 'rule')->create(['team_id' => $team->id, 'client_ip' => '198.51.100.4', 'ja4_id' => null, 'lifted_at' => now()]);

    $this->withToken('edge-token')
        ->getJson(route('edge.gym.triggers'))
        ->assertExactJson(['triggers' => [
            ['id' => $routine->id, 'hosts' => ['sign-example.preview.example', 'sign.example'], 'method' => null, 'path' => '*', 'clients' => [['ip' => '203.0.113.7', 'ja4' => 'q13d0314h3_55b375c5d22e_79cc91d6b50c']]],
        ]]);
});

test('a Livewire login attempt from a visitor who belongs in the gym is told to reload the page it came from', function () {
    gymbrosRoutine()->update(['conditions' => []]);

    test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => 'sign.example', 'X-NiceTry-Uri' => '/livewire/message/filament.core.auth.login', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'X-Livewire' => 'true', 'Accept' => 'text/html, application/xhtml+xml', 'Referer' => 'https://sign.example/dashboard/login?next=1'])
        ->get(route('edge.gym.check'))
        ->assertOk()
        ->assertExactJson(['effects' => ['html' => null, 'dirty' => [], 'redirect' => '/dashboard/login?next=1'], 'serverMemo' => []])
        ->assertCookie(GymSession::SESSION_COOKIE, 'reload', encrypted: false);

    test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => 'sign.example', 'X-NiceTry-Uri' => '/api/status', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'Accept' => 'application/json', 'Referer' => 'https://evil.example/phish'])
        ->get(route('edge.gym.check'))
        ->assertExactJson(['redirect' => '/']);

    expect(GymSession::count())->toBe(0);
});

test('the reload a background request asks for starts the gym, and a visitor mid-session keeps their session', function () {
    gymbrosRoutine()->update(['conditions' => []]);

    gymCheck('sign.example', '/dashboard/login', [GymSession::SESSION_COOKIE => 'reload'])
        ->assertOk()
        ->assertSee('Spot me, bro');

    $session = GymSession::sole();

    test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => 'sign.example', 'X-NiceTry-Uri' => '/livewire/message/login', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'X-Livewire' => 'true'])
        ->withUnencryptedCookies([GymSession::SESSION_COOKIE => $session->token])
        ->get(route('edge.gym.check'))
        ->assertOk()
        ->assertCookieMissing(GymSession::SESSION_COOKIE);

    expect(GymSession::count())->toBe(1);
});

test('a visitor sent to the gym keeps the client hints the edge passes on from the start', function () {
    gymbrosRoutine();

    test()->withToken('edge-token')
        ->withHeaders(['X-NiceTry-Host' => 'sign.example', 'X-NiceTry-Uri' => '/gymbros', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'Sec-CH-UA' => '"Chromium";v="152"', 'Sec-CH-UA-Platform' => '"iOS"'])
        ->get(route('edge.gym.check'))
        ->assertOk();

    expect(GymSession::sole()->clientHint->value)->toBe('"Chromium";v="152" | "iOS"');
});
