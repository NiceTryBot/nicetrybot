<?php

use App\Actions\Rules\EvaluateRule;
use App\Actions\Rules\SaveEdgeRule;
use App\Addons\Addons;
use App\Data\RuleScript;
use App\Enums\GymSessionStatus;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymSession;
use App\Models\RequestPath;
use App\Models\ScopeVerdict;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use App\Support\RuleDestinationError;
use App\Support\RuleDestinations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use NiceTry\Bouncer\Models\BouncerDomain;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\SwarmMode\Models\SwarmRule;
use NiceTry\SwarmMode\SwarmModePack;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

/**
 * Install the Swarm Mode pack for a user's team, as the user, and return its rules by pack key.
 *
 * @return array<string, EdgeRule>
 */
function installSwarmPack(User $user): array
{
    test()->actingAs($user);
    app(InstallRulePack::class)->handle($user->currentTeam, new SwarmModePack);

    return RulePackInstall::query()->where('team_id', $user->currentTeam->id)->with('rule')->get()
        ->mapWithKeys(fn (RulePackInstall $install) => [$install->item => $install->rule])
        ->all();
}

/**
 * IPs each getting one answer from the origin on a path, a few seconds apart.
 */
function swarmOn(Domain $domain, string $path, int $status = 403, int $ips = 3, RequestAction $action = RequestAction::Passed): void
{
    foreach (range(1, $ips) as $i) {
        EdgeRequest::factory()->for($domain)->create(['client_ip' => "198.51.100.{$i}", 'path' => $path, 'status_code' => $status, 'action' => $action, 'occurred_at' => now()->subSeconds(10 + $i)]);
    }
}

/**
 * Put a site, or one path on it, into Swarm Mode by hand, as one of the team's swarm rules would.
 */
function swarmed(Domain $domain, EdgeRule $rule, ?string $path = null): ScopeVerdict
{
    return ScopeVerdict::create([
        'team_id' => $domain->team_id,
        'edge_rule_id' => $rule->id,
        'tag' => 'swarm',
        'domain_id' => $domain->id,
        'path_id' => $path === null ? null : RequestPath::idFor($path),
        'matched_requests' => 3,
        'first_matched_at' => now()->subMinute(),
        'last_matched_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(4),
    ]);
}

/**
 * Ask the gym check about one request to shop.example, the way the edge does, from a current Chrome.
 *
 * @param  array<string, string>  $headers
 */
function swarmCheck(string $uri, array $headers = [], string $ip = '203.0.113.7')
{
    return test()->withToken('edge-token')
        ->withHeaders(array_merge([
            'X-NiceTry-Host' => 'shop.example',
            'X-NiceTry-Uri' => $uri,
            'X-NiceTry-Client-Ip' => $ip,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/999.0.0.0 Safari/537.36',
            'Sec-CH-UA' => '"Chromium";v="999"',
            'Sec-CH-UA-Platform' => '"Windows"',
        ], $headers))
        ->get(route('edge.gym.check'));
}

/**
 * A user whose team enforces on shop.example with the bouncer on, the pack installed, and its Bouncer
 * rule switched on.
 *
 * @return array{0: Domain, 1: array<string, EdgeRule>}
 */
function swarmSite(): array
{
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    BouncerDomain::create(['domain_id' => $domain->id, 'enabled' => true]);
    $rules = installSwarmPack($user);
    $rules['swarm-bounce']->update(['enabled' => true]);
    auth()->forgetGuards();

    return [$domain, $rules];
}

test('the pack installs its swarm rules switched on and its Bouncer rule switched off, under Modes and Timeouts', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create();
    $rules = installSwarmPack($user);

    // Without the Bouncer add-on, the Bouncer rule doesn't parse, so the pack leaves it out.
    $withBouncer = app(Addons::class)->has('bouncer');

    expect(array_keys($rules))->toEqualCanonicalizing(['swarm-pages', 'swarm-livewire', 'swarm-api', ...($withBouncer ? ['swarm-bounce'] : [])])
        ->and($rules['swarm-pages']->enabled)->toBeTrue()
        ->and($rules['swarm-livewire']->enabled)->toBeTrue()
        ->and($rules['swarm-api']->enabled)->toBeTrue()
        ->and($withBouncer ? $rules['swarm-bounce']->enabled : false)->toBeFalse()
        ->and(SwarmRule::query()->pluck('edge_rule_id')->sort()->values()->all())->toBe(collect([$rules['swarm-pages']->id, $rules['swarm-livewire']->id, $rules['swarm-api']->id])->sort()->values()->all());

    $lanes = collect(app(RuleDestinations::class)->board($user->currentTeam, $user->currentTeam->edgeRules()->get()))->keyBy(fn (array $lane) => $lane['column']->key);

    expect($lanes['swarm']['column']->tab)->toBe('modes')
        ->and($lanes['swarm']['cards'])->toHaveCount(3)
        ->and($lanes['swarm']['cards'][0]['destinations'][0]->note)->toBe('Off right now');

    Livewire::test('pages::rules.index')
        ->set('tab', 'modes')
        ->assertSee(['Modes · 3', 'Swarm on a page', 'Swarm on Livewire', 'Swarm on an API'])
        ->assertSeeHtml('data-test="board-column-swarm"');
});

test('a page swarms on its own, Livewire swarms the whole site, and dead links, assets and the edge\'s own answers never count', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example']);
    $rules = installSwarmPack($user);
    DB::table('edge_rules')->update(['last_request_id' => 0]);

    swarmOn($domain, '/login', 403);
    swarmOn($domain, '/old-listing', 404);
    swarmOn($domain, '/broken-page', 500);
    swarmOn($domain, '/missing.png', 403);
    swarmOn($domain, '/checkout', 403, action: RequestAction::Bouncer);
    swarmOn($domain, '/account', 419, ips: 2);
    swarmOn($domain, '/livewire-f7c6ec91/update', 419);

    foreach (['swarm-pages', 'swarm-livewire', 'swarm-api'] as $key) {
        app(EvaluateRule::class)->handle($rules[$key]->fresh());
    }

    $paths = DB::table('request_paths')->pluck('path', 'id');
    $covered = ScopeVerdict::query()->orderBy('id')->get()->map(fn (ScopeVerdict $verdict) => $verdict->tag.' '.($verdict->path_id === null ? '(site)' : $paths[$verdict->path_id]))->all();

    expect($covered)->toBe(['swarm-page /login', 'swarm-livewire (site)'])
        ->and(Verdict::count())->toBe(0);
});

test('while a URL is in Swarm Mode the Bouncer checks every visitor there, and only there', function () {
    [$domain, $rules] = swarmSite();
    swarmed($domain, $rules['swarm-pages'], '/login');

    swarmCheck('/login')->assertForbidden()->assertHeader('X-NiceTry-Action', 'bouncer');
    swarmCheck('/', ip: '203.0.113.8')->assertNoContent();
})->skip(fn () => ! app(Addons::class)->has('bouncer'), 'Needs the Bouncer add-on, which needs the paid Fingerprints.');

test('a site in Swarm Mode has every page load checked', function () {
    [$domain, $rules] = swarmSite();
    swarmed($domain, $rules['swarm-livewire']);

    swarmCheck('/')->assertForbidden();
    swarmCheck('/pricing', ip: '203.0.113.8')->assertForbidden();
})->skip(fn () => ! app(Addons::class)->has('bouncer'), 'Needs the Bouncer add-on, which needs the paid Fingerprints.');

test('a swarmed API answers a script with a 429 in JSON naming the challenge, and lets its IP through once someone clears it', function () {
    [$domain, $rules] = swarmSite();
    swarmed($domain, $rules['swarm-api'], '/api/orders');

    $first = swarmCheck('/api/orders', ['Accept' => 'application/json'])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertHeader('X-NiceTry-Hold', '10')
        ->assertHeader('X-NiceTry-Action', 'bouncer')
        ->assertJsonPath('error', 'verification_required');

    $session = GymSession::sole();
    $challenge = 'https://shop.example/_q/'.$session->token;

    expect($first->json('challenge_url'))->toBe($challenge)
        ->and($first->headers->get('Nice-Challenge'))->toBe($challenge)
        ->and($session->client_ip)->toBe('203.0.113.7');

    // The script calls again: the same challenge, not another.
    swarmCheck('/api/orders')->assertStatus(429)->assertJsonPath('challenge_url', $challenge);
    expect(GymSession::count())->toBe(1);

    // Someone opens the link in a browser and clears it: that IP's calls go through, another's don't.
    $session->update(['status' => GymSessionStatus::Cleared, 'finished_at' => now()]);

    swarmCheck('/api/orders')->assertNoContent();
    swarmCheck('/api/orders', ip: '203.0.113.99')->assertStatus(429);

    // An API URL nobody's swarming is left alone.
    swarmCheck('/api/products', ip: '203.0.113.99')->assertNoContent();
})->skip(fn () => ! app(Addons::class)->has('bouncer'), 'Needs the Bouncer add-on, which needs the paid Fingerprints.');

test('a page\'s own call to a swarmed API is told to reload into the challenge, not handed JSON', function () {
    [$domain, $rules] = swarmSite();
    swarmed($domain, $rules['swarm-api'], '/api/orders');

    swarmCheck('/api/orders', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'Referer' => 'https://shop.example/account'])
        ->assertOk()
        ->assertExactJson(['redirect' => '/account']);
})->skip(fn () => ! app(Addons::class)->has('bouncer'), 'Needs the Bouncer add-on, which needs the paid Fingerprints.');

test('SWARM takes only a rule over host or path, and a client destination never takes one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $save = fn (string $source) => app(SaveEdgeRule::class)->handle($user->currentTeam, null, ['name' => 'x', 'source' => $source, 'enabled' => true], RuleScript::parse($source));

    expect(fn () => $save("TAG ip AS 'x' FOR 5 MINUTES SWARM WHERE status = 403"))->toThrow(RuleDestinationError::class, 'host or path')
        ->and(fn () => $save("TAG host AS 'x' FOR 5 MINUTES TARPIT 'x' WHERE status = 403"))->toThrow(RuleDestinationError::class, 'ip or ja4')
        ->and(EdgeRule::count())->toBe(0);

    $save("TAG host, path AS 'x' FOR 5 MINUTES SWARM WHERE status = 403 WITHIN 1 MINUTE HAVING COUNT(DISTINCT ip) >= 3");

    expect(SwarmRule::count())->toBe(1);
});

test('rules can read swarm from the logs: the requests made while their URL was in Swarm Mode', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();
    $swarm = EdgeRule::factory()->for($team)->script("TAG host, path AS 'swarm' FOR 5 MINUTES WHERE status = 403")->create();
    SwarmRule::create(['edge_rule_id' => $swarm->id]);
    swarmed($domain, $swarm, '/login');
    $during = EdgeRule::factory()->for($team)->script("TAG ip AS 'during' FOR 1 HOUR WHERE swarm IS NOT NULL")->create(['last_request_id' => 0]);

    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.1', 'path' => '/login', 'occurred_at' => now()->subSeconds(5)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.2', 'path' => '/about', 'occurred_at' => now()->subSeconds(5)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.3', 'path' => '/login', 'occurred_at' => now()->subMinutes(10)]);

    app(EvaluateRule::class)->handle($during);

    expect(Verdict::query()->where('edge_rule_id', $during->id)->pluck('client_ip')->all())->toBe(['203.0.113.1']);
});

test('installing the add-on installs its pack for every team, as the team\'s owner', function () {
    $user = User::factory()->create();

    (require base_path('packages/swarm-mode/database/migrations/2026_11_14_000002_preinstall_swarm_mode_pack.php'))->up();

    expect(RulePackInstall::query()->where('team_id', $user->currentTeam->id)->where('pack', 'swarm-mode')->count())->toBe(app(Addons::class)->has('bouncer') ? 4 : 3)
        ->and(SwarmRule::count())->toBe(3)
        ->and(auth()->user())->toBeNull();
});
