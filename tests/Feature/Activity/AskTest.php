<?php

use App\Models\AiCredential;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a question becomes the WHERE Claude writes for it, with the period it names', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.com']);
    EdgeRequest::factory()->for($domain)->notFound('/wp-login.php')->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/checkout', 'status_code' => 200]);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer([
        'search' => 'status = 404',
        'period' => '7d',
        'note' => 'Guessed that “missing” means a 404.',
    ]))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('question', 'missing pages this week')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', 'status = 404')
        ->assertSet('period', '7d')
        ->assertSet('question', 'missing pages this week')
        ->assertSee('Guessed that “missing” means a 404.')
        ->assertSee('/wp-login.php')
        ->assertDontSee('/checkout');

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'missing pages this week')
        && str_contains($request['messages'][0]['content'], 'shop.example.com'));
});

test('a question can pick the spoofed-clients filter, and a fresh question lets it go', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Domain::factory()->for($user->currentTeam)->create(['hostname' => 'settlement.example']);
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/v1/messages')
        ->push(claudeSearchAnswer(['search' => "host = 'settlement.example'", 'client' => 'spoofed']))
        ->push(claudeSearchAnswer(['search' => 'status = 404']));

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('question', 'show me all traffic to settlement.example from spoofed clients')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', "host = 'settlement.example'")
        ->assertSet('client', 'spoofed')
        ->assertSet('domain', 'settlement.example')
        ->assertSee('+ spoofed clients');

    $page->set('question', 'missing pages')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('client', '');

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], '<current_client_filter>spoofed</current_client_filter>'));
});

test('a question refines the search, while the hidden noise stays hidden and out of Claude\'s sight', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Domain::factory()->for($user->currentTeam)->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => "status = 404 AND country = 'CN'"]))]);
    session(['activity.hide' => 'assets']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "status = 404\nAND category != 'asset'")
        ->set('question', 'only from china')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', "status = 404\nAND country = 'CN'\nAND category != 'asset'");

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<current_search>\nstatus = 404\n</current_search>"));
});

test('a question that is already a search is used as it is, without asking Claude', function (string $question, string $search) {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(claudeSearchAnswer(['search' => 'status = 500']))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('question', $question)
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', $search);

    Http::assertNothingSent();
})->with([
    'a WHERE' => ["status = 404 AND path LIKE '/wp-%'", "status = 404 AND path LIKE '/wp-%'"],
    'an IP' => ['203.0.113.9', "ip = '203.0.113.9'"],
    'an IP range' => ['66.249.64.0/19', "ip = '66.249.64.0/19'"],
    'a TLS fingerprint' => ['t13d1517h2_8daaf6152771_b6f405a00624', "ja4 = 't13d1517h2_8daaf6152771_b6f405a00624'"],
]);

test('without a key, a question is searched for as words', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(claudeSearchAnswer(['search' => 'status = 500']))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('question', 'digital ocean')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', "'digital ocean'");

    Http::assertNothingSent();
});

test('without a key, the search offers to connect Claude', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertSee('Connect Claude to ask in plain English')
        ->assertSee(route('ai.edit'));
});

test('when Claude can\'t help, the search stays as it was and says why', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'status = 500')
        ->set('question', 'missing pages')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', 'status = 500')
        ->assertSet('askError', 'Anthropic didn’t accept your API key. Check it in Settings → Artificial Intelligence.')
        ->assertSee('Anthropic didn’t accept your API key.');
});

test('a question that isn\'t about requests leaves the search alone, with Claude\'s reason', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => '', 'note' => 'That isn’t a question about requests.']))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'status = 500')
        ->set('question', 'what is the weather like')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', 'status = 500')
        ->assertSet('askError', 'That isn’t a question about requests.');
});

test('editing the WHERE by hand lets the question go', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('question', 'missing pages')
        ->set('search', 'status = 404')
        ->assertSet('question', '');
});

test('the question\'s words can be searched as typed instead', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'status = 404')
        ->set('question', 'missing pages')
        ->call('searchWords')
        ->assertSet('search', "'missing pages'");
});

test('clearing the question keeps the hidden noise hidden', function () {
    $user = User::factory()->create();
    session(['activity.hide' => 'assets']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "status = 404\nAND category != 'asset'")
        ->set('question', 'missing pages')
        ->call('clearQuestion')
        ->assertSet('question', '')
        ->assertSet('search', "category != 'asset'");
});

test('a question about IPs lists the IPs Claude\'s HAVING keeps', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'settlement.example']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.3', 'path' => '/c/thank-you']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.1', 'path' => '/a/thank-you']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.1', 'path' => '/']);
    $search = "host = 'settlement.example' GROUP BY ip HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0";
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => $search, 'view' => 'clients']))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('loadCounts')
        ->set('question', 'show me all IPs on settlement.example that has a */thank-you visit that never visited the homepage')
        ->call('ask')
        ->call('$refresh')
        ->call('$refresh')
        ->assertSet('search', $search)
        ->assertSet('view', 'clients')
        ->assertSeeText('IPs (1)')
        ->assertSee('198.51.100.3')
        ->assertDontSee('198.51.100.1');
});
