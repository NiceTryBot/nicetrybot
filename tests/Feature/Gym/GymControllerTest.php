<?php

use App\Enums\ClientPlatform;
use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Gym\HashPuzzle;
use App\Models\GymRoutine;
use App\Models\GymSession;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * Start a real (non-preview) session in a routine, as the edge hand-off would.
 */
function gymSessionIn(GymRoutine $routine, array $attributes = []): GymSession
{
    $session = GymSession::factory()->create(['team_id' => $routine->team_id, ...$attributes]);
    $session->routines()->attach($routine);

    return $session;
}

/**
 * Find a nonce for a challenge the way the browser does: in order from zero.
 */
function solvePuzzle(string $challenge, int $bits): string
{
    for ($nonce = 0; ! HashPuzzle::verify($challenge, (string) $nonce, $bits); $nonce++) {
        //
    }

    return (string) $nonce;
}

test('a visitor who solves the proof of work reloads the page they asked for, carrying a pass', function () {
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create(['pass_seconds' => 3600]);
    $session = gymSessionIn($routine, ['destination' => 'https://shop.example.com/checkout']);
    $step = $routine->obstacles[0]['id'];

    $this->get(route('gym.show', $session))->assertSee('Checking your browser');
    $challenge = $session->fresh()->state($step)->get('challenge');

    $this->postJson(route('gym.attempt', $session), ['step' => $step, 'challenge' => $challenge, 'nonce' => solvePuzzle($challenge, 12)])
        ->assertExactJson(['status' => 'cleared', 'message' => null, 'redirect' => 'https://shop.example.com/checkout'])
        ->assertCookie(GymSession::PASS_COOKIE);

    expect($session->fresh())
        ->status->toBe(GymSessionStatus::Cleared)
        ->exit_action->toBe(GymExit::Continue)
        ->hashes->toBe(4096);
});

test('a wrong proof of work is refused with a fresh challenge', function () {
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create();
    $session = gymSessionIn($routine);
    $step = $routine->obstacles[0]['id'];
    $this->get(route('gym.show', $session));
    $challenge = $session->fresh()->state($step)->get('challenge');
    $wrongNonce = collect(range(0, 50))->first(fn (int $nonce) => ! HashPuzzle::verify($challenge, (string) $nonce, 12));

    $response = $this->postJson(route('gym.attempt', $session), ['step' => $step, 'challenge' => $challenge, 'nonce' => (string) $wrongNonce]);

    $response->assertJsonPath('status', 'retry')->assertJsonPath('difficulty', 12);
    expect($response->json('challenge'))->not->toBe($challenge)
        ->and($session->fresh()->state($step)->isCleared())->toBeFalse();
});

test('a wrong code draws a new one, and the right code clears the obstacle', function () {
    $routine = GymRoutine::factory()->obstacles(['screen-code' => ['length' => 5]])->create();
    $session = gymSessionIn($routine);
    $step = $routine->obstacles[0]['id'];
    $this->get(route('gym.show', $session))->assertSee('Type the code you see');
    $firstCode = Crypt::decryptString($session->fresh()->state($step)->get('code'));

    $this->post(route('gym.attempt', $session), ['step' => $step, 'code' => 'WRONG'])
        ->assertRedirect(route('gym.show', $session));
    $this->get(route('gym.show', $session))->assertSee('That’s not it. Here’s a fresh code.');
    $secondCode = Crypt::decryptString($session->fresh()->state($step)->get('code'));
    $this->travel(3)->seconds();
    $this->post(route('gym.attempt', $session), ['step' => $step, 'code' => strtolower($secondCode)]);

    expect($session->fresh()->state($step))
        ->isCleared()->toBeTrue()
        ->flags->toBe([])
        ->and($secondCode)->not->toBe($firstCode);
});

test('a code typed faster than a person could is flagged and escalates to the routine\'s next obstacle', function () {
    $routine = GymRoutine::factory()->create(['obstacles' => [
        ['id' => 'code', 'type' => 'screen-code', 'config' => [], 'escalate' => 'waiting-room'],
    ]]);
    $session = gymSessionIn($routine);
    $this->get(route('gym.show', $session));
    $code = Crypt::decryptString($session->fresh()->state('code')->get('code'));

    $this->post(route('gym.attempt', $session), ['step' => 'code', 'code' => $code]);

    expect($session->fresh())
        ->escalations->toBe([['key' => 'code~waiting-room', 'type' => 'waiting-room', 'after' => 'code']])
        ->state('code')->flags->toHaveCount(1);
    $this->get(route('gym.show', $session))->assertSee('You’re in the queue');
});

test('the waiting room releases a visitor only once the time is served with steady work', function () {
    $this->freezeTime();
    $routine = GymRoutine::factory()->obstacles(['waiting-room' => ['seconds' => 10, 'intensity' => 'light']])->create();
    $session = gymSessionIn($routine);
    $step = $routine->obstacles[0]['id'];
    $this->get(route('gym.show', $session));
    $round = function () use ($session, $step) {
        $challenge = $session->fresh()->state($step)->get('challenge');

        return $this->postJson(route('gym.attempt', $session), ['step' => $step, 'challenge' => $challenge, 'nonce' => solvePuzzle($challenge, 20)]);
    };

    $this->travel(6)->seconds();
    $round()->assertJsonPath('status', 'progress')->assertJsonPath('elapsedMs', 6000);
    $this->travel(31)->seconds();
    $round()->assertJsonPath('status', 'progress')->assertJsonPath('elapsedMs', 0)->assertJsonPath('message', 'The work paused, so the clock started over.');
    $this->travel(6)->seconds();
    $round()->assertJsonPath('status', 'progress');
    // One slow request (a browser stuck opening a connection, then retrying) doesn't start the clock over.
    $this->travel(20)->seconds();
    $round()->assertJsonPath('status', 'cleared');

    expect($session->fresh()->state($step)->get('rounds'))->toBe(2);
});

test('a probe scanner gets its 404 from the gym after the work', function () {
    $routine = GymRoutine::factory()->create(['obstacles' => [], 'exit_action' => GymExit::NotFound]);
    $session = gymSessionIn($routine);

    $this->get(route('gym.show', $session))
        ->assertNotFound()
        ->assertSee('This page could not be found.')
        ->assertCookieMissing(GymSession::PASS_COOKIE);

    expect($session->fresh()->status)->toBe(GymSessionStatus::TurnedAway);
});

test('stacked routines run each obstacle once and exit through the strictest exit', function () {
    $first = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create(['position' => 1, 'exit_action' => GymExit::Continue]);
    $second = GymRoutine::factory()->for($first->team)->obstacles(['proof-of-work' => ['difficulty' => 24], 'screen-code' => []])->create(['position' => 2, 'exit_action' => GymExit::Block]);
    $session = gymSessionIn($first);
    $session->routines()->attach($second);
    $powStep = $first->obstacles[0]['id'];
    $this->get(route('gym.show', $session));
    $challenge = $session->fresh()->state($powStep)->get('challenge');

    $this->postJson(route('gym.attempt', $session), ['step' => $powStep, 'challenge' => $challenge, 'nonce' => solvePuzzle($challenge, 12)])
        ->assertJsonPath('status', 'cleared');
    $this->get(route('gym.show', $session))->assertSee('Type the code you see')->assertSee('Step 2 of 2');
    $codeStep = $second->obstacles[1]['id'];
    $this->travel(3)->seconds();
    $this->post(route('gym.attempt', $session), ['step' => $codeStep, 'code' => Crypt::decryptString($session->fresh()->state($codeStep)->get('code'))]);

    $this->get(route('gym.show', $session))->assertForbidden();
});

test('an obstacle added to a routine reaches visitors already in the gym', function () {
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create();
    $session = gymSessionIn($routine);
    $this->get(route('gym.show', $session))->assertSee('Checking your browser');

    $routine->update(['obstacles' => [['id' => 'new-code', 'type' => 'screen-code', 'config' => [], 'escalate' => null], ...$routine->obstacles]]);

    $this->get(route('gym.show', $session))->assertSee('Type the code you see');
});

test('an attempt at a step the visitor isn\'t on reloads their page to pick up where they are', function () {
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create();
    $session = gymSessionIn($routine);
    $this->get(route('gym.show', $session));

    $this->postJson(route('gym.attempt', $session), ['step' => 'not-a-step', 'nonce' => '1'])
        ->assertExactJson(['status' => 'moved', 'redirect' => 'https://shop.example.com/checkout']);

    expect($session->fresh()->state($routine->obstacles[0]['id'])->attempts)->toBe(0);
});

test('a preview that clears every obstacle shows how it went instead of redirecting', function () {
    $session = GymSession::factory()->preview()->create([
        'draft' => [['name' => 'Draft', 'obstacles' => [], 'exit' => GymExit::Continue->value, 'pass_seconds' => 900]],
    ]);

    $this->get(route('gym.show', $session))
        ->assertOk()
        ->assertSee('You made it through')
        ->assertSee('How it went')
        ->assertCookieMissing(GymSession::PASS_COOKIE);
});

test('an unknown session token is not found', function () {
    $this->get(route('gym.show', ['gymSession' => 'no-such-token']))->assertNotFound();
});

test('the obstacle preview draws a configured obstacle only with a valid signature', function () {
    $config = rtrim(strtr(base64_encode(json_encode(['length' => 7])), '+/', '-_'), '=');

    $this->get(URL::signedRoute('gym.demo', ['obstacle' => 'screen-code', 'config' => $config]))
        ->assertOk()
        ->assertSee('placeholder="•••••••"', escape: false);
    $this->get(route('gym.demo', ['obstacle' => 'screen-code', 'config' => $config]))->assertForbidden();
});

test('attempts the edge vouches for record which client did the work, and a switch of IP is flagged', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create();
    $session = gymSessionIn($routine, ['client_ip' => '203.0.113.7', 'ja4' => 't13d1516h2_8daaf6152771_02713d6af862']);
    $this->get(route('gym.show', $session));
    $attempt = fn (array $headers) => $this->withHeaders($headers)->postJson(route('gym.attempt', $session), ['step' => $routine->obstacles[0]['id'], 'challenge' => 'stale', 'nonce' => '1']);

    $attempt(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'X-NiceTry-JA4' => 't13d1516h2_8daaf6152771_02713d6af862']);
    // The same browser moving to HTTP/3 presents a different JA4.
    $attempt(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '203.0.113.7', 'X-NiceTry-JA4' => 'q13d0314h3_55b375c5d22e_79cc91d6b50c']);

    expect($session->fresh())
        ->attempt_clients->toHaveCount(2)
        ->switchedClients()->toBeFalse();

    $attempt(['Authorization' => 'Bearer not-the-token', 'X-NiceTry-Client-Ip' => '198.51.100.9', 'X-NiceTry-JA4' => 't13d190900_9dc949149365_97f8aa674fd9']);

    expect($session->fresh()->attempt_clients)->toHaveCount(2);

    $attempt(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '198.51.100.9', 'X-NiceTry-JA4' => 't13d190900_9dc949149365_97f8aa674fd9']);

    expect($session->fresh())
        ->attempt_clients->toHaveCount(3)
        ->switchedClients()->toBeTrue();
});

test('the gym keeps the client hints the visitor\'s own browser sends, and a hand-off\'s with its attempt', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    $routine = GymRoutine::factory()->obstacles(['proof-of-work' => ['difficulty' => 12]])->create();
    $session = gymSessionIn($routine, ['client_ip' => '203.0.113.7']);
    $hints = fn (string $platform) => ['Sec-CH-UA' => '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"', 'Sec-CH-UA-Platform' => $platform];

    $this->withHeaders(['X-NiceTry-Client-Ip' => '203.0.113.7', ...$hints('"iOS"')])->get(route('gym.show', $session));

    expect($session->fresh()->client_hints_id)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '198.51.100.9', ...$hints('"Linux"')])->get(route('gym.show', $session));

    expect($session->fresh()->client_hints_id)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '203.0.113.7', ...$hints('"Windows"')])->get(route('gym.show', $session));

    expect($session->fresh()->clientHint->value)->toBe('"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "Windows"');

    $this->withHeaders(['Authorization' => 'Bearer edge-token', 'X-NiceTry-Client-Ip' => '198.51.100.9', ...$hints('"iOS"')])
        ->postJson(route('gym.attempt', $session), ['step' => $routine->obstacles[0]['id'], 'challenge' => 'stale', 'nonce' => '1']);

    expect($session->fresh())
        ->clientHint->value->toEndWith('| "Windows"')
        ->clientProfile('198.51.100.9')->hintsPlatform->toBe(ClientPlatform::Ios)
        ->clientProfile('203.0.113.7')->hintsPlatform->toBe(ClientPlatform::Windows);
});
