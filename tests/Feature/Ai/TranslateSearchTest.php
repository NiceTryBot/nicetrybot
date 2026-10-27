<?php

use App\Actions\Ai\TranslateSearch;
use App\Data\RuleScriptParser;
use App\Enums\TrafficPeriod;
use App\Models\AiCredential;
use App\Support\AnthropicException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('a question goes to Claude with the user\'s key, and comes back as a search', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00'));
    $credential = AiCredential::factory()->create(['secret' => 'sk-ant-api03-test-key-0123456789abcdef']);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer([
        'search' => "status = 404 AND host = 'shop.example.com'",
        'period' => '7d',
        'note' => 'Guessed “the shop” is shop.example.com.',
    ]))]);

    $translated = app(TranslateSearch::class)->handle($credential, '404s on the shop this week', domains: ['shop.example.com', 'blog.example.com']);

    expect($translated)
        ->search->toBe("status = 404 AND host = 'shop.example.com'")
        ->period->toBe(TrafficPeriod::Week)
        ->note->toBe('Guessed “the shop” is shop.example.com.');
    expect($credential->refresh()->last_used_at->toDateTimeString())->toBe('2026-10-14 09:30:00');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key', 'sk-ant-api03-test-key-0123456789abcdef')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
        && ! $request->hasHeader('anthropic-workspace-id')
        && $request['model'] === 'claude-opus-5'
        && $request['fallbacks'] === 'default'
        && $request['output_config']['effort'] === 'low'
        && $request['output_config']['format']['type'] === 'json_schema'
        && str_contains($request['messages'][0]['content'], "<question>\n404s on the shop this week\n</question>")
        && str_contains($request['messages'][0]['content'], "shop.example.com\nblog.example.com"));
});

test('a key for a whole organization names its workspace on every request', function () {
    $credential = AiCredential::factory()->create(['workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ']);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => 'status = 404']))]);

    app(TranslateSearch::class)->handle($credential, 'missing pages');

    Http::assertSent(fn (Request $request) => $request->hasHeader('anthropic-workspace-id', 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ'));
});

test('the current search goes along, so a question can refine it', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => "status = 404 AND country = 'CN'"]))]);

    app(TranslateSearch::class)->handle($credential, 'only from china', 'status = 404');

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<current_search>\nstatus = 404\n</current_search>"));
});

test('a question about spoofed clients picks the client filter the WHERE can\'t say', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => "host = 'shop.example.com'", 'client' => 'spoofed']))]);

    $translated = app(TranslateSearch::class)->handle($credential, 'traffic to the shop from spoofed clients', client: 'outdated');

    expect($translated)
        ->search->toBe("host = 'shop.example.com'")
        ->client->toBe('spoofed');
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], '<current_client_filter>outdated</current_client_filter>')
        && in_array('client', $request['output_config']['format']['schema']['required'], true));
});

test('a team with many hostnames sends the ones the question names, and counts the rest by parent domain', function () {
    $credential = AiCredential::factory()->create();
    $previews = array_map(fn (int $number) => sprintf('a-%03d.preview.example', $number), range(0, 249));
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => "host = 'settlement.example'"]))]);

    app(TranslateSearch::class)->handle($credential, 'all traffic to settlement.example', domains: [...$previews, 'settlement.example', 'www.settlement.example']);

    Http::assertSent(function (Request $request) {
        $content = $request['messages'][0]['content'];

        return str_contains($content, "<hostnames shown=\"200\" total=\"252\">\nsettlement.example\nwww.settlement.example\na-000.preview.example")
            && str_contains($content, 'a-197.preview.example')
            && ! str_contains($content, 'a-198.preview.example')
            && str_contains($content, "<hostnames_per_parent_domain>\npreview.example: 250\nsettlement.example: 2\n</hostnames_per_parent_domain>");
    });
});

test('Claude gets one chance to fix a search that doesn\'t parse', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/v1/messages')
        ->push(claudeSearchAnswer(['search' => 'status == 404']))
        ->push(claudeSearchAnswer(['search' => 'status = 404']));

    $translated = app(TranslateSearch::class)->handle($credential, 'missing pages');

    expect($translated->search)->toBe('status = 404');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "Your last answer was:\n<search>\nstatus == 404\n</search>\nIt doesn't parse"));
});

test('a search that still doesn\'t parse after the fix is an error', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => 'status == 404']))]);

    expect(fn () => app(TranslateSearch::class)->handle($credential, 'missing pages'))
        ->toThrow(AnthropicException::class, 'Claude wrote a search that doesn’t parse');
    Http::assertSentCount(2);
});

test('the same question is translated once, then reused', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => 'status = 404']))]);

    app(TranslateSearch::class)->handle($credential, 'missing pages');
    $again = app(TranslateSearch::class)->handle($credential, 'missing pages');

    expect($again->search)->toBe('status = 404');
    Http::assertSentCount(1);
});

test('a refusal is an error, not an empty search', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null]])]);

    expect(fn () => app(TranslateSearch::class)->handle($credential, 'something odd'))
        ->toThrow(AnthropicException::class, 'Claude declined to answer that one. Try wording it differently.');
});

test('Anthropic errors come back worded for the key\'s owner', function (int $status, string $message) {
    Sleep::fake();
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['type' => 'error', 'error' => ['type' => 'error', 'message' => 'Your credit balance is too low.']], $status)]);

    expect(fn () => app(TranslateSearch::class)->handle($credential, 'missing pages'))
        ->toThrow(AnthropicException::class, $message);
})->with([
    'rejected key' => [401, 'Anthropic didn’t accept your API key. Check it in Settings → Artificial Intelligence.'],
    'rate limited' => [429, 'Anthropic is rate limiting your key. Try again in a moment.'],
    'overloaded' => [529, 'Anthropic is busy right now. Try again in a moment.'],
    'anything else' => [400, 'Anthropic said: Your credit balance is too low.'],
]);

test('the worked examples Claude learns from all parse', function (string $search) {
    expect((new RuleScriptParser($search))->parseSearch())->toBeArray();
})->with(fn () => collect(TranslateSearch::EXAMPLES)->pluck('search')->filter()->values()->all());

test('a slow answer becomes a message after one try, inside the page\'s time', function () {
    $credential = AiCredential::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds')]);

    expect(fn () => app(TranslateSearch::class)->handle($credential, 'missing pages'))
        ->toThrow(AnthropicException::class, 'Claude took too long to answer. Try again, or ask something simpler.');
    Http::assertSentCount(1);
});
