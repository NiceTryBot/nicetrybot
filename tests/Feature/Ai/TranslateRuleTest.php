<?php

use App\Actions\Ai\TranslateRule;
use App\Data\RuleScript;
use App\Models\AiCredential;
use App\Support\AnthropicException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NiceTry\Blackhole\Models\BlackholeFeeder;

const PHPMYADMIN_RULE = "TAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nWHERE host = 'mysite.com' AND path LIKE '%phpmyadmin%'";

test('a request goes to Claude with the team\'s hostnames, gym routines and destinations, and comes back as a rule', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer([
        'name' => 'phpMyAdmin probes on mysite.com',
        'source' => PHPMYADMIN_RULE,
    ]))]);

    $translated = app(TranslateRule::class)->handle(
        $credential,
        'send everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym',
        ['mysite.com', 'blog.example.com'],
        [['id' => 7, 'name' => 'Login wall', 'entry' => 'Visitors whose path starts with “/login”.']],
        destinations: ['GYM', 'TARPIT'],
    );

    expect($translated)
        ->name->toBe('phpMyAdmin probes on mysite.com')
        ->source->toBe(PHPMYADMIN_RULE)
        ->note->toBeNull();
    Http::assertSent(fn (Request $request) => $request['output_config']['format']['type'] === 'json_schema'
        && in_array('source', $request['output_config']['format']['schema']['required'], true)
        && str_contains($request['messages'][0]['content'], "<hostnames>\nmysite.com\nblog.example.com\n</hostnames>")
        && str_contains($request['messages'][0]['content'], "<gym_routines>\n7: Login wall. Visitors whose path starts with “/login”.\n</gym_routines>")
        && str_contains($request['messages'][0]['content'], '<destinations>GYM, TARPIT</destinations>')
        && str_contains($request['messages'][0]['content'], "<request>\nsend everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym\n</request>"));
});

test('Claude writes the destination into the script', function () {
    $credential = AiCredential::factory()->create();
    $source = "TAG ip AS 'probe' FOR 1 DAY\nGYM 'Login wall'\nWHERE path LIKE '%phpmyadmin%'";
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['source' => $source]))]);

    $translated = app(TranslateRule::class)->handle($credential, 'put phpmyadmin probes through the login wall');

    expect($translated->source)->toBe($source)
        ->and(RuleScript::parse($translated->source)->destinations)->toBe(['GYM' => 'Login wall']);
});

test('the draft goes along, so a request can change it', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['source' => PHPMYADMIN_RULE]))]);

    app(TranslateRule::class)->handle($credential, 'make it a week', current: PHPMYADMIN_RULE);

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<current_rule>\n".PHPMYADMIN_RULE."\n</current_rule>")
        && str_contains($request['messages'][0]['content'], '<destinations>GYM</destinations>'));
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
})->with(fn () => collect(TranslateRule::EXAMPLES)
    ->flatMap(fn (array $example) => [$example['source'], $example['current'] ?? ''])
    ->filter()
    // BLACKHOLE only parses with the paid Blackhole add-on installed.
    ->reject(fn (string $source) => ! class_exists(BlackholeFeeder::class) && str_contains($source, 'BLACKHOLE'))
    ->values()
    ->all());
