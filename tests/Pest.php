<?php

use App\Enums\ApiAbility;
use App\Mcp\ApiContext;
use App\Mcp\Servers\NiceTryBotServer;
use App\Models\Administrator;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../packages/*/tests/Feature', '../packages/captchas/captchas/*/*/tests');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Read a config file as it comes out with these environment variables, a null one being unset, so
 * a test can see what an .env makes of it whatever the machine's own .env holds.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function configFileWith(string $file, array $env): array
{
    $saved = [];

    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            putenv($process === false ? $key : "{$key}={$process}");
        }
    }
}

/**
 * The body of a Claude API message answering an Activity search question, as Http::fake() returns it.
 *
 * @param  array{search: string, period?: string, client?: string, view?: string, note?: string}  $answer
 * @return array<string, mixed>
 */
function claudeSearchAnswer(array $answer, string $stopReason = 'end_turn'): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'test-signature'],
            ['type' => 'text', 'text' => json_encode(['period' => '', 'client' => '', 'view' => 'requests', 'note' => '', ...$answer])],
        ],
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 2000, 'output_tokens' => 40],
    ];
}

/**
 * The body of a Claude API message answering a request for a rule, as Http::fake() returns it.
 *
 * @param  array{name?: string, source: string, routine_id?: int, routine_name?: string, note?: string}  $answer
 * @return array<string, mixed>
 */
function claudeRuleAnswer(array $answer): array
{
    return [
        ...claudeSearchAnswer([]),
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'test-signature'],
            ['type' => 'text', 'text' => json_encode(['name' => '', 'routine_id' => 0, 'routine_name' => '', 'note' => '', ...$answer])],
        ],
    ];
}

/**
 * An API token for an administrator on their own team, and the plain token an agent would send.
 *
 * @param  list<ApiAbility>  $abilities
 * @return array{0: ApiToken, 1: string}
 */
function apiToken(array $abilities = [], ?User $user = null): array
{
    $user ??= User::factory()->create();

    if (! $user->isAdministrator()) {
        Administrator::factory()->for($user)->create();
        $user->unsetRelation('administrator');
    }

    return ApiToken::issue($user, $user->currentTeam, 'Test agent', $abilities);
}

/**
 * The MCP server, as the agent holding a token sees it.
 */
function mcpAs(ApiToken $token): PendingTestResponse
{
    app(ApiContext::class)->use($token);

    return NiceTryBotServer::actingAs($token->user);
}
