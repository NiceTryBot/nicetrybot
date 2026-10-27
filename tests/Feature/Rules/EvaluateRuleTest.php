<?php

use App\Actions\Clients\FindCurrentFirefoxReleases;
use App\Actions\Rules\EvaluateRule;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Support\Facades\Http;

/**
 * Create a rule for the user's team that starts watching from now, as saving one does.
 */
function watchingRule(User $user, string $source): EdgeRule
{
    return EdgeRule::factory()->for($user->currentTeam)->script($source)->create(['last_request_id' => (int) EdgeRequest::max('id')]);
}

test('a probe request bans its IP and fingerprint for the rule\'s duration', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, "BAN ip, ja4 FOR 30 DAYS WHERE category = 'probe'");
    $probe = EdgeRequest::factory()->for($domain)->withFingerprint()->create(['client_ip' => '203.0.113.7', 'path' => '/.env.local']);
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['client_ip' => '198.51.100.4', 'path' => '/contact']);

    $reached = app(EvaluateRule::class)->handle($rule);

    $verdict = Verdict::sole();

    expect($reached)->toBe(1)
        ->and($verdict->client_ip)->toBe('203.0.113.7')
        ->and($verdict->ja4_id)->toBe($probe->ja4_id)
        ->and($verdict->expires_at->diffInDays(now()->addDays(30)))->toBeLessThan(1)
        ->and($rule->fresh()->last_request_id)->toBe(EdgeRequest::max('id'));
});

test('a new rule never acts on requests from before it existed', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env']);
    $rule = watchingRule($user, "BAN ip FOR 1 DAY WHERE category = 'probe'");

    expect(app(EvaluateRule::class)->handle($rule))->toBe(0)
        ->and(Verdict::count())->toBe(0);
});

test('a subject that keeps matching extends its verdict instead of getting another', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, "BAN ip FOR 1 DAY WHERE category = 'probe'");

    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'path' => '/.env']);
    app(EvaluateRule::class)->handle($rule);
    EdgeRequest::factory()->for($domain)->count(2)->create(['client_ip' => '203.0.113.7', 'path' => '/.git/config']);
    app(EvaluateRule::class)->handle($rule->fresh());

    expect(Verdict::sole()->matched_requests)->toBe(3);
});

test('two 404s across two hosts within the window ban the IP', function () {
    $user = User::factory()->create();
    [$first, $second] = Domain::factory()->for($user->currentTeam)->count(2)->create();
    $rule = watchingRule($user, 'BAN ip FOR 1 HOUR WHERE status = 404 WITHIN 10 MINUTES HAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2');
    EdgeRequest::factory()->for($first)->notFound()->create(['client_ip' => '203.0.113.7', 'occurred_at' => now()->subMinutes(3)]);
    EdgeRequest::factory()->for($second)->notFound()->create(['client_ip' => '203.0.113.7', 'occurred_at' => now()->subMinutes(1)]);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::sole()->client_ip)->toBe('203.0.113.7')
        ->and(Verdict::sole()->expires_at->diffInMinutes(now()->addHour()))->toBeLessThan(1);
});

test('404s on a single host, or outside the window, are not a sweep', function () {
    $user = User::factory()->create();
    [$first, $second] = Domain::factory()->for($user->currentTeam)->count(2)->create();
    $rule = watchingRule($user, 'BAN ip FOR 1 HOUR WHERE status = 404 WITHIN 10 MINUTES HAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2');
    EdgeRequest::factory()->for($first)->notFound()->count(3)->create(['client_ip' => '203.0.113.7', 'occurred_at' => now()->subMinutes(2)]);
    EdgeRequest::factory()->for($second)->notFound()->create(['client_ip' => '198.51.100.4', 'occurred_at' => now()->subMinutes(30)]);
    EdgeRequest::factory()->for($first)->notFound()->create(['client_ip' => '198.51.100.4', 'occurred_at' => now()->subMinutes(1)]);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::count())->toBe(0);
});

test('conditions compare user agents without regard to case and read fingerprint and network fields', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, "TAG ip AS 'ua-tls-mismatch' FOR 1 DAY WHERE ua LIKE '%chrome/%' AND ja4.alpn = '00' AND network.kind = 'hosting'");
    EdgeRequest::factory()->for($domain)->fromNetwork()->withFingerprint('t13d131100_f57a46bbacb6_ab7e3b40a677')->create(['client_ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 Chrome/124.0.0.0']);
    EdgeRequest::factory()->for($domain)->fromNetwork()->withFingerprint()->create(['client_ip' => '198.51.100.4', 'user_agent' => 'Mozilla/5.0 Chrome/124.0.0.0']);
    EdgeRequest::factory()->for($domain)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->withFingerprint('t13d131100_f57a46bbacb6_ab7e3b40a677')->create(['client_ip' => '192.0.2.9', 'user_agent' => 'Mozilla/5.0 Chrome/124.0.0.0']);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::pluck('client_ip')->all())->toBe(['203.0.113.7'])
        ->and(Verdict::sole()->tag)->toBe('ua-tls-mismatch');
});

test('only enabled rules run on schedule', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRule::factory()->for($user->currentTeam)->disabled()->create(['last_request_id' => 0]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env']);

    $this->artisan('rules:evaluate')->expectsOutputToContain('Reached 0 new verdict(s).')->assertSuccessful();

    expect(Verdict::count())->toBe(0);
});

test('quotes and SQL inside rule values are matched as plain text', function (string $source) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, $source);
    EdgeRequest::factory()->for($domain)->count(2)->create(['path' => '/login']);

    expect(app(EvaluateRule::class)->handle($rule))->toBe(0)
        ->and(Verdict::count())->toBe(0)
        ->and(EdgeRequest::count())->toBe(2);
})->with([
    'always-true comparison' => ["BAN ip FOR 1 DAY WHERE path = 'x'' OR ''1''=''1'"],
    'stacked statement' => ["BAN ip FOR 1 DAY WHERE path LIKE '%''; DELETE FROM edge_requests; --%'"],
    'escaped quote' => ["BAN ip FOR 1 DAY WHERE ua = '\\' OR 1=1 --'"],
]);

test('UNTIL lifts a verdict once its subject makes a matching request, and only then', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, "TAG ip AS 'failed-login' FOR 1 DAY\nWHERE method = 'POST' AND path = '/login'\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 3\nUNTIL method = 'GET' AND path = '/dashboard' AND status = 200");
    $request = fn (string $ip, string $method, string $path, int $status) => EdgeRequest::factory()->for($domain)->create([
        'client_ip' => $ip,
        'method' => HttpMethod::fromVerb($method),
        'path' => $path,
        'status_code' => $status,
        'occurred_at' => now(),
    ]);
    foreach (range(1, 3) as $attempt) {
        $request('203.0.113.7', 'POST', '/login', 200);
    }

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::sole()->isActive())->toBeTrue();

    $this->travel(1)->minute();
    $request('203.0.113.7', 'GET', '/dashboard', 302);
    $request('198.51.100.4', 'GET', '/dashboard', 200);
    app(EvaluateRule::class)->handle($rule->fresh());

    expect(Verdict::sole()->isActive())->toBeTrue();

    $this->travel(1)->minute();
    $request('203.0.113.7', 'GET', '/dashboard', 200);
    app(EvaluateRule::class)->handle($rule->fresh());

    expect(Verdict::sole())
        ->isActive()->toBeFalse()
        ->lifted_at->not->toBeNull();
});

test('a rule can tag automated Chrome by its client hints, and a script by its missing language', function (string $source, string $taggedIp) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, $source);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'client_hints' => '"Chromium";v="142", "HeadlessChrome";v="142" | "Linux"', 'accept_language' => 'en-US']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'client_hints' => '"Google Chrome";v="154", "Chromium";v="154" | "macOS"', 'accept_language' => 'en-US']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '192.0.2.9', 'client_hints' => null, 'accept_language' => null]);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::sole()->client_ip)->toBe($taggedIp);
})->with([
    'headless in the brand list' => ["TAG ip AS 'headless' FOR 1 DAY WHERE hints LIKE '%HeadlessChrome%'", '203.0.113.7'],
    'no Accept-Language at all' => ["TAG ip AS 'no-lang' FOR 1 DAY WHERE lang IS NULL", '192.0.2.9'],
]);

test('a rule can match or leave out an IP range', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, "TAG ip AS 'script-tls' FOR 1 DAY WHERE ua LIKE '%Chrome/%' AND NOT ip IN ('66.249.70.0/24')");
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '66.249.70.6', 'user_agent' => 'Mozilla/5.0 (Linux; Android 6.0.1) Chrome/141.0 (compatible; Googlebot/2.1)']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.104', 'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/131.0']);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::sole()->client_ip)->toBe('198.51.100.104');
});

test('a rule can count only some of a client\'s requests, to catch one that did something but never another', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'settlement.example']);
    $rule = watchingRule($user, "TAG ip AS 'skipped-home' FOR 1 DAY WHERE host = 'settlement.example' WITHIN 1 HOUR HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0");
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'path' => '/claim/thank-you', 'occurred_at' => now()->subMinutes(5)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/', 'occurred_at' => now()->subMinutes(6)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/claim/thank-you', 'occurred_at' => now()->subMinutes(5)]);

    $reached = app(EvaluateRule::class)->handle($rule);

    expect($reached)->toBe(1)
        ->and(Verdict::sole()->client_ip)->toBe('203.0.113.7');
});

test('a rule can pick clients by what their parts give away', function (string $source, array $flagged) {
    $firefox = fn (int $version) => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$version}.0) Gecko/20100101 Firefox/{$version}.0";
    $firefoxTls = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';
    $iosHints = '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"';
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::response(['LATEST_FIREFOX_VERSION' => '158.0', 'FIREFOX_ESR' => '153.5.0esr'])]);
    app(FindCurrentFirefoxReleases::class)->refresh();
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = watchingRule($user, $source);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(150), $firefoxTls, $iosHints)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(150), $firefoxTls)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.2']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(158), $firefoxTls, $iosHints)->create(['client_ip' => '203.0.113.3']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(150), $firefoxTls)->fromNetwork(22616, 'Zscaler', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.4']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(150), $firefoxTls)->create(['client_ip' => '203.0.113.5', 'asn' => null]);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::orderBy('client_ip')->pluck('client_ip')->all())->toBe($flagged);
})->with([
    'spoofed and outdated' => ["TAG ip AS 'x' FOR 1 DAY WHERE client = 'spoofed' AND client = 'outdated'", ['203.0.113.1']],
    'outdated, not behind a corporate gateway' => ["TAG ip AS 'x' FOR 1 DAY WHERE client = 'outdated' AND client != 'corporate'", ['203.0.113.1', '203.0.113.2', '203.0.113.5']],
    'either kind' => ["TAG ip AS 'x' FOR 1 DAY WHERE client IN ('spoofed', 'corporate')", ['203.0.113.1', '203.0.113.3', '203.0.113.4']],
]);
