<?php

use App\Actions\Ai\TranslateRule;
use App\Data\RuleScript;
use App\Models\AiCredential;
use App\Support\AnthropicException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PHPMYADMIN_RULE = "TAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nWHERE host = 'mysite.com' AND path LIKE '%phpmyadmin%'";

test('a request goes to Claude with the team\'s hostnames and gym routines, and comes back as a rule', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer([
        'name' => 'phpMyAdmin probes on mysite.com',
        'source' => PHPMYADMIN_RULE,
        'routine_name' => 'PhpMyAdmin Gym',
    ]))]);

    $translated = app(TranslateRule::class)->handle(
        $credential,
        'send everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym',
        ['mysite.com', 'blog.example.com'],
        [['id' => 7, 'name' => 'Login wall', 'entry' => 'Visitors whose path starts with “/login”.']],
    );

    expect($translated)
        ->name->toBe('phpMyAdmin probes on mysite.com')
        ->source->toBe(PHPMYADMIN_RULE)
        ->routineId->toBeNull()
        ->routineName->toBe('PhpMyAdmin Gym');
    Http::assertSent(fn (Request $request) => $request['output_config']['format']['type'] === 'json_schema'
        && in_array('routine_id', $request['output_config']['format']['schema']['required'], true)
        && str_contains($request['messages'][0]['content'], "<hostnames>\nmysite.com\nblog.example.com\n</hostnames>")
        && str_contains($request['messages'][0]['content'], "<gym_routines>\n7: Login wall. Visitors whose path starts with “/login”.\n</gym_routines>")
        && str_contains($request['messages'][0]['content'], "<request>\nsend everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym\n</request>"));
});

test('a routine Claude picks from the team\'s comes back by id, without a new name', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['source' => PHPMYADMIN_RULE, 'routine_id' => 7, 'routine_name' => 'Login wall']))]);

    $translated = app(TranslateRule::class)->handle($credential, 'put phpmyadmin probes through the login wall');

    expect($translated)
        ->routineId->toBe(7)
        ->routineName->toBeNull();
});

test('the draft and its routine go along, so a request can change them', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['source' => PHPMYADMIN_RULE]))]);

    app(TranslateRule::class)->handle($credential, 'make it a week', current: PHPMYADMIN_RULE, currentRoutine: 'PhpMyAdmin Gym (new)');

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<current_rule>\n".PHPMYADMIN_RULE."\n</current_rule>")
        && str_contains($request['messages'][0]['content'], '<current_gym_routine>PhpMyAdmin Gym (new)</current_gym_routine>'));
});

test('Claude gets one chance to fix a rule that doesn\'t parse', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/v1/messages')
        ->push(claudeRuleAnswer(['source' => "TAG ip FOR 1 DAY WHERE path LIKE '%phpmyadmin%'"]))
        ->push(claudeRuleAnswer(['source' => PHPMYADMIN_RULE]));

    $translated = app(TranslateRule::class)->handle($credential, 'tag phpmyadmin probes');

    expect($translated->source)->toBe(PHPMYADMIN_RULE);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "Your last answer was:\n<source>\nTAG ip FOR 1 DAY")
        && str_contains($request['messages'][0]['content'], 'TAG needs a name'));
});

test('a rule that still doesn\'t parse after the fix is an error', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['source' => 'TAG ip FOR 1 DAY']))]);

    expect(fn () => app(TranslateRule::class)->handle($credential, 'tag everyone'))
        ->toThrow(AnthropicException::class, 'Claude wrote a rule that doesn’t parse');
    Http::assertSentCount(2);
});

test('the worked examples Claude learns from all parse', function (string $source) {
    expect(RuleScript::parse($source))->toBeInstanceOf(RuleScript::class);
})->with(fn () => collect(TranslateRule::EXAMPLES)->flatMap(fn (array $example) => [$example['source'], $example['current'] ?? ''])->filter()->values()->all());
