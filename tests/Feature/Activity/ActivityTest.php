<?php

use App\Enums\GymSessionStatus;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('the activity log shows only the current team\'s requests', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/our-page']);
    EdgeRequest::factory()->create(['path' => '/their-page']);

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('/our-page')
        ->assertDontSee('/their-page');
});

test('the activity log can be narrowed to suspicious 404s', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim']);
    EdgeRequest::factory()->for($domain)->notFound('/apple-touch-icon.png')->create();
    EdgeRequest::factory()->for($domain)->probe(RequestAction::Diverted)->create(['status_code' => 200, 'path' => '/wp-login.php']);
    EdgeRequest::factory()->for($domain)->probe()->create(['path' => '/.git/config']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('status', '404')
        ->set('action', 'suspicious')
        ->assertSee('/.git/config')
        ->assertDontSee('/apple-touch-icon.png')
        ->assertDontSee('/wp-login.php')
        ->assertDontSee('/claim');
});

test('the activity log can be narrowed to requests kept off the origin', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim']);
    EdgeRequest::factory()->for($domain)->probe()->create(['path' => '/.git/config']);
    EdgeRequest::factory()->for($domain)->probe(RequestAction::Diverted)->create(['path' => '/wp-login.php']);
    EdgeRequest::factory()->for($domain)->probe(RequestAction::Blocked)->create(['path' => '/xmlrpc.php']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('action', 'kept-off')
        ->assertSee('/wp-login.php')
        ->assertSee('/xmlrpc.php')
        ->assertDontSee('/.git/config')
        ->assertDontSee('/claim');
});

test('requests older than the selected period are hidden', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/last-week', 'occurred_at' => now()->subDays(3)]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertDontSee('/last-week')
        ->set('period', '7d')
        ->assertSee('/last-week');
});

test('the last minute and last hour periods hide anything older', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/just-now', 'occurred_at' => now()->subSeconds(20)]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/half-hour-ago', 'occurred_at' => now()->subMinutes(30)]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/this-morning', 'occurred_at' => now()->subHours(3)]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('period', '1m')
        ->assertSee('/just-now')
        ->assertDontSee('/half-hour-ago')
        ->set('period', '1h')
        ->assertSee('/half-hour-ago')
        ->assertDontSee('/this-morning');
});

test('requests logged in the same second are listed newest first', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $sameSecond = now()->startOfSecond();
    foreach (['/first-hop', '/second-hop', '/third-hop'] as $path) {
        EdgeRequest::factory()->for($domain)->create(['path' => $path, 'occurred_at' => $sameSecond]);
    }

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSeeInOrder(['/third-hop', '/second-hop', '/first-hop']);
});

test('another team\'s request cannot be inspected', function () {
    $user = User::factory()->create();
    $foreignRequest = EdgeRequest::factory()->create(['path' => '/their-secret-path']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $foreignRequest->id)
        ->assertSet('inspectedRequestId', null)
        ->assertDontSee('/their-secret-path');
});

test('the filtered activity log can be exported as CSV', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    EdgeRequest::factory()->for($domain)->probe()->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('export')
        ->assertFileDownloaded(contentType: 'text/csv');
});

test('the activity log can be narrowed to one TLS fingerprint', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->withFingerprint('t13d131100_f57a46bbacb6_ab7e3b40a677')->create(['path' => '/from-the-script']);
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['path' => '/from-the-browser']);

    $this->actingAs($user);

    Livewire::withQueryParams(['ja4' => 't13d131100_f57a46bbacb6_ab7e3b40a677'])
        ->test('pages::activity.index')
        ->assertSet('search', "ja4 = 't13d131100_f57a46bbacb6_ab7e3b40a677'")
        ->assertSee('/from-the-script')
        ->assertDontSee('/from-the-browser');
});

test('pasting a JA4 into search filters by that exact fingerprint', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->withFingerprint('t13d131100_f57a46bbacb6_ab7e3b40a677')->create(['path' => '/from-the-script']);
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['path' => '/from-the-browser']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', ' T13D131100_F57A46BBACB6_AB7E3B40A677 ')
        ->assertSet('search', "ja4 = 't13d131100_f57a46bbacb6_ab7e3b40a677'")
        ->assertSee('/from-the-script')
        ->assertDontSee('/from-the-browser');
});

test('each request row links its fingerprint to the filtered log', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->withFingerprint()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('ja4=t13d1518h2_8daaf6152771_e2d80978ab2e', escape: false)
        ->assertSee('t13d1518h2');
});

test('the request detail decodes the fingerprint and links to everything that shares it', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->withFingerprint()->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $request->id)
        ->assertSee('t13d1518h2_8daaf6152771_e2d80978ab2e')
        ->assertSee('TLS 1.3 · 15 ciphers · 18 extensions · offers HTTP/2')
        ->assertSee('Everything with this fingerprint');
});

test('the activity log can be narrowed to one network', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->create(['path' => '/from-ovh']);
    EdgeRequest::factory()->for($domain)->fromNetwork(7922, 'Comcast Cable Communications', NetworkKind::Unknown)->create(['path' => '/from-comcast']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'asn = 16276')
        ->assertSee('/from-ovh')
        ->assertDontSee('/from-comcast');
});

test('the activity log can be narrowed to one country', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->locatedIn('CA', 'Quebec', 'Montreal')->create(['path' => '/from-canada']);
    EdgeRequest::factory()->for($domain)->locatedIn('US', 'Virginia', 'Dulles')->create(['path' => '/from-the-us']);

    $this->actingAs($user);

    Livewire::withQueryParams(['country' => 'ca'])
        ->test('pages::activity.index')
        ->assertSet('search', "country = 'CA'")
        ->assertSee('/from-canada')
        ->assertDontSee('/from-the-us');
});

test('the activity log can be narrowed to one user agent', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $scripted = EdgeRequest::factory()->for($domain)->create(['path' => '/scripted', 'user_agent' => 'python-requests/2.32.3']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/browsed', 'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15']);

    $this->actingAs($user);

    Livewire::withQueryParams(['ua' => (string) $scripted->user_agent_id])
        ->test('pages::activity.index')
        ->assertSet('search', "ua = 'python-requests/2.32.3'")
        ->assertSee('/scripted')
        ->assertDontSee('/browsed');
});

test('searching by network name finds that network\'s requests', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork(14061, 'DigitalOcean, LLC')->create(['path' => '/from-digitalocean']);
    EdgeRequest::factory()->for($domain)->fromNetwork(7922, 'Comcast Cable Communications', NetworkKind::Unknown)->create(['path' => '/from-comcast']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'digitalocean')
        ->assertSee('/from-digitalocean')
        ->assertDontSee('/from-comcast');
});

test('the activity log can be narrowed to one exact path', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $home = EdgeRequest::factory()->for($domain)->create(['path' => '/home-page']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim-form']);

    $this->actingAs($user);

    Livewire::withQueryParams(['path' => (string) $home->path_id])
        ->test('pages::activity.index')
        ->assertSet('search', "path = '/home-page'")
        ->assertSee('/home-page')
        ->assertDontSee('/claim-form');
});

test('the activity log can be narrowed to one path category', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env.local']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/.aws/credentials']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/contact-us']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "category = 'probe/creds'")
        ->assertSee('/.env.local')
        ->assertSee('/.aws/credentials')
        ->assertDontSee('/contact-us');
});

test('NOT keeps requests without the value it tests, like pages with no path category', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env.local']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/favicon.ico']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/contact-us']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "NOT category = 'probe' AND NOT category = 'asset'")
        ->assertSee('/contact-us')
        ->assertDontSee('/.env.local')
        ->assertDontSee('/favicon.ico');
});

test('each request shows its country as a flag with the country name on hover', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->locatedIn('NL', 'North Holland', 'Amsterdam')->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-country="NL"', escape: false)
        ->assertSee('title="Netherlands"', escape: false);
});

test('a diverted request shows the gym visit it started', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Gymbros']);
    $request = EdgeRequest::factory()->for($domain)->create(['path' => '/gymbros', 'action' => RequestAction::Diverted, 'client_ip' => '203.0.113.7', 'occurred_at' => now()->subMinute()]);
    $session = GymSession::factory()->create([
        'team_id' => $user->currentTeam->id,
        'domain_id' => $domain->id,
        'client_ip' => '203.0.113.7',
        'ja4' => 't13d1516h2_8daaf6152771_02713d6af862',
        'status' => GymSessionStatus::Cleared,
        'hashes' => 13_600_000,
        'started_at' => now()->subMinute()->subSecond(),
        'finished_at' => now()->subSeconds(50),
        'attempt_clients' => [['ip' => '198.51.100.9', 'ja4' => 't13d190900_9dc949149365_97f8aa674fd9']],
    ]);
    $session->routines()->attach($routine);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $request->id)
        ->assertSee('Sent to the gym (Gymbros)')
        ->assertSee('got through')
        ->assertSee('14M hashes')
        ->assertSeeHtml('data-test="gym-switched-clients"');
});

test('a diverted request says how its gym visit ended, or that it\'s still going', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $visit = fn (string $ip, array $attributes = []) => GymSession::factory()->create(['team_id' => $user->currentTeam->id, 'domain_id' => $domain->id, 'client_ip' => $ip, ...$attributes]);
    $diverted = fn (string $ip, CarbonImmutable $at) => EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Diverted, 'client_ip' => $ip, 'occurred_at' => $at]);

    $diverted('203.0.113.1', now()->subMinute());
    $visit('203.0.113.1', ['started_at' => now()->subMinutes(2), 'last_seen_at' => now()->subMinute()]);
    $diverted('203.0.113.2', now()->subMinutes(5));
    $visit('203.0.113.2', ['status' => GymSessionStatus::Cleared, 'started_at' => now()->subMinutes(5)->subSeconds(4), 'finished_at' => now()->subMinutes(5)]);
    $diverted('203.0.113.3', now()->subHour());
    $visit('203.0.113.3', ['started_at' => now()->subHour(), 'last_seen_at' => now()->subHour()]);
    $diverted('203.0.113.4', now()->subHours(2));

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->assertSeeInOrder(['Still training', 'Cleared', '4 s', 'Gave up']);

    expect(substr_count($page->html(), 'data-test="gym-outcome"'))->toBe(3);
});

test('assets, probes and plain home page visits can be hidden, and the choice is remembered', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/', 'method' => HttpMethod::Post]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/build/app.js']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/favicon.ico']);
    EdgeRequest::factory()->for($domain)->probe()->create(['path' => '/.env']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim']);

    $this->actingAs($user);

    $paths = fn ($component) => $component->instance()->requests->map(fn (EdgeRequest $request) => $request->method->name.' '.$request->path)->sort()->values()->all();

    $component = Livewire::test('pages::activity.index');
    expect($component->instance()->requests->count())->toBe(6);

    // Each toggle is a clause in the search.
    $component->call('toggleHide', 'assets')->call('toggleHide', 'probes')->call('toggleHide', 'home')
        ->assertSet('search', "category != 'asset'\nAND category != 'probe'\nAND NOT (method = 'GET' AND path = '/')");

    expect($paths($component))->toBe(['Get /claim', 'Post /']);

    $component->call('toggleHide', 'probes')->assertSet('search', "category != 'asset'\nAND NOT (method = 'GET' AND path = '/')");
    expect($paths($component))->toBe(['Get /.env', 'Get /claim', 'Post /']);

    // The next visit starts with the same things hidden; an explicit URL wins.
    Livewire::test('pages::activity.index')->assertSet('search', "category != 'asset'\nAND NOT (method = 'GET' AND path = '/')");
    Livewire::withQueryParams(['hide' => ''])->test('pages::activity.index')->assertSet('search', '');
    Livewire::withQueryParams(['q' => 'status = 404'])->test('pages::activity.index')->assertSet('search', 'status = 404');
});

test('a request\'s path can be categorized by hand, and the patterns leave it alone', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->create(['path' => '/reports/quarterly-summary']);
    $twin = EdgeRequest::factory()->for($domain)->create(['path' => '/reports/quarterly-summary']);

    expect($request->requestPath->category)->toBeNull();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('categorizePath', $request->id, 'probe/config')
        ->assertHasNoErrors()
        ->call('toggleHide', 'probes');

    $path = $request->requestPath->fresh();

    expect($path->category)->toBe(PathCategory::ProbeConfig)
        ->and($path->categorized_by)->toBe($user->id)
        ->and($path->isCategorizedByHand())->toBeTrue()
        ->and($twin->fresh()->requestPath->category)->toBe(PathCategory::ProbeConfig);

    $this->artisan('edge:categorize-paths')->assertSuccessful();

    expect($path->fresh()->category)->toBe(PathCategory::ProbeConfig);

    Livewire::test('pages::activity.index')->call('categorizePath', $request->id, 'none');
    expect($path->fresh())->category->toBeNull()->categorized_at->not->toBeNull();

    Livewire::test('pages::activity.index')->call('categorizePath', $request->id, 'auto');
    expect($path->fresh())->category->toBeNull()->categorized_at->toBeNull();
});

test('only rule managers can categorize, and only their own team\'s requests', function () {
    $owner = User::factory()->create();
    $theirs = EdgeRequest::factory()->create(['path' => '/their/thing.json']);

    $this->actingAs($owner);

    Livewire::test('pages::activity.index')->call('categorizePath', $theirs->id, 'probe/creds');

    expect($theirs->requestPath->fresh()->category)->toBeNull();

    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $request = EdgeRequest::factory()->for(Domain::factory()->for($team))->create(['path' => '/maybe.json']);

    $this->actingAs($member);

    Livewire::test('pages::activity.index')->call('categorizePath', $request->id, 'probe/creds')->assertForbidden();
});

test('the search is a WHERE clause in the rule language', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/wp-login.php', 'method' => HttpMethod::Post]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/wp-login.php']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim', 'method' => HttpMethod::Post]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "path LIKE '/wp-%'\nAND method = 'POST'")
        ->assertSee('Requests where path starts with “/wp-” and method is “POST”.')
        ->assertSee('/wp-login.php')
        ->assertDontSee('/claim')
        ->tap(fn ($component) => expect($component->instance()->requests->count())->toBe(1));
});

test('a search that does not parse says where, and matches nothing', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'staus = 404')
        ->assertSeeHtml('data-test="search-error"')
        ->assertSee('Did you mean status?')
        ->assertDontSee('/claim');
});

test('the filters switch clauses of the search on and off, and follow what it says', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    EdgeRequest::factory()->for($domain)->notFound('/missing')->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim']);

    $this->actingAs($user);

    $component = Livewire::test('pages::activity.index')
        ->set('search', "path LIKE '/%'")
        ->set('status', '404')
        ->set('domain', 'claims.example.com')
        ->assertSet('search', "path LIKE '/%'\nAND status = 404\nAND host = 'claims.example.com'")
        ->assertSee('/missing')
        ->assertDontSee('/claim');

    // Picking another choice swaps the clause; "Any status" takes it out.
    $component->set('status', '4xx')
        ->assertSet('search', "path LIKE '/%'\nAND host = 'claims.example.com'\nAND (status >= 400 AND status < 500)")
        ->set('status', '')
        ->assertSet('search', "path LIKE '/%'\nAND host = 'claims.example.com'");

    // Writing a clause by hand moves the select to match.
    $component->set('search', "ACTION IN ('observed', 'diverted', 'blocked') and status=404")
        ->assertSet('action', 'suspicious')
        ->assertSet('status', '404')
        ->assertSet('domain', '');
});

test('plain text in the search matches it anywhere, and filters add to it', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->notFound('/wp-admin/setup.php')->create(['client_ip' => '203.0.113.50']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/wp-admin/', 'client_ip' => '203.0.113.50']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/claim', 'client_ip' => '198.51.100.4']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', '203.0.113.50')
        ->assertSee('/wp-admin/')
        ->assertDontSee('/claim')
        ->set('status', '404')
        ->assertSet('search', "'203.0.113.50'\nAND status = 404")
        ->assertSee('/wp-admin/setup.php')
        ->tap(fn ($component) => expect($component->instance()->requests->count())->toBe(1));
});

test('the chart above the list counts what the search finds, humans and suspicious apart', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->notFound('/a-missing-page')->create(['action' => RequestAction::Passed]);
    EdgeRequest::factory()->for($domain)->probe()->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/a-fine-page', 'status_code' => 200, 'action' => RequestAction::Passed]);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('search', 'status = 404')
        ->call('loadCounts')
        ->assertSeeHtml('data-test="activity-chart"');

    $buckets = collect($page->instance()->chartBuckets);

    expect($buckets->sum('humans'))->toBe(1)
        ->and($buckets->sum('suspicious'))->toBe(1);
});

test('the chart draws a bar per unit finer than the period, or per the size picked', function (string $period, string $interval, int $bars) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:30:00'));
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')->set('period', $period)->set('chartInterval', $interval);

    expect($page->instance()->chartBuckets)->toHaveCount($bars);
})->with([
    'the last hour by minute' => ['1h', '', 60],
    'the last 24 hours by hour' => ['24h', '', 24],
    'the last 7 days by hour' => ['7d', '', 157],
    'the last 7 days by day' => ['7d', '1d', 7],
    'the last 14 days by 6 hours' => ['14d', '', 55],
    'the last 21 days by day' => ['21d', '1d', 21],
    'the last 30 days by day' => ['30d', '', 30],
    'a size the period doesn\'t offer' => ['7d', '1s', 157],
]);

test('a request lands in its hour\'s bar, and a new period forgets a size it doesn\'t offer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:30:00'));
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => '2026-10-12 09:15:00', 'action' => RequestAction::Passed]);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')->set('period', '7d');

    expect(collect($page->instance()->chartBuckets)->firstWhere('key', '2026-10-12 09:00:00')['humans'])->toBe(1);

    $page->set('chartInterval', '1d')->set('period', '1h')->assertSet('chartInterval', '');
});

test('a stretch picked on the chart narrows the list, not the chart or the search', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:30:00'));
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/in-the-stretch', 'occurred_at' => '2026-10-14 09:15:00', 'action' => RequestAction::Passed]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/after-the-stretch', 'occurred_at' => '2026-10-14 11:15:00', 'action' => RequestAction::Passed]);
    $range = CarbonImmutable::parse('2026-10-14 09:00:00')->getTimestamp().'-'.CarbonImmutable::parse('2026-10-14 10:00:00')->getTimestamp();

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('search', 'method = \'GET\'')
        ->call('loadCounts')
        ->assertSeeHtml('x-data="chartRange(')
        ->set('range', $range)
        ->assertSet('range', $range)
        ->assertSet('search', 'method = \'GET\'')
        ->assertSee('/in-the-stretch')
        ->assertDontSee('/after-the-stretch')
        ->assertSee('09:00–10:00');

    expect($page->instance()->requests->count())->toBe(1);
    expect(collect($page->instance()->chartBuckets)->sum('humans'))->toBe(2);

    $page->set('range', '')->assertSee('/after-the-stretch');
});

test('a stretch that isn\'t one is let go', function (string $range) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:30:00'));
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('range', $range)
        ->assertSet('range', '');
})->with([
    'not two numbers' => ['yesterday-today'],
    'backwards' => ['1760443200-1760439600'],
    'before the period' => ['1600000000-1600003600'],
]);

test('a new period or bar size lets the picked stretch go', function (string $property, string $value) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:30:00'));
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create();
    $range = CarbonImmutable::parse('2026-10-14 09:00:00')->getTimestamp().'-'.CarbonImmutable::parse('2026-10-14 10:00:00')->getTimestamp();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('range', $range)
        ->set($property, $value)
        ->assertSet('range', '');
})->with([
    'a new period' => ['period', '7d'],
    'a new bar size' => ['chartInterval', '15m'],
]);

test('the chart is headed by how many requests the search found, across how many websites', function () {
    $user = User::factory()->create();
    [$shop, $blog, $docs] = Domain::factory()->for($user->currentTeam)->count(3)->create();
    EdgeRequest::factory()->for($shop)->count(2)->create(['path' => '/llms.txt']);
    EdgeRequest::factory()->for($blog)->create(['path' => '/llms.txt']);
    EdgeRequest::factory()->for($docs)->create(['path' => '/about']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "path = '/llms.txt'")
        ->call('loadCounts')
        ->assertSee('3 requests found across 2 websites')
        ->set('search', "path = '/about'")
        ->assertDontSee('requests found')
        ->call('loadCounts')
        ->assertSee('1 request found across 1 website');
});

test('the list shows as many rows per page as picked', function () {
    $user = User::factory()->create();
    EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam))->count(30)->create();

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index');
    expect(substr_count($page->html(), 'data-test="activity-row"'))->toBe(25);

    $page->set('perPage', 100);
    expect(substr_count($page->html(), 'data-test="activity-row"'))->toBe(30);
});

test('a page size the list doesn\'t offer falls back to 25', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::withQueryParams(['per' => 100000])
        ->test('pages::activity.index')
        ->assertSet('perPage', 25)
        ->set('perPage', 5000)
        ->assertSet('perPage', 25);
});

test('a diverted request names the routines its visitor is in', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Diverted, 'client_ip' => '203.0.113.1', 'occurred_at' => now()->subMinute()]);
    $session = GymSession::factory()->create(['team_id' => $user->currentTeam->id, 'domain_id' => $domain->id, 'client_ip' => '203.0.113.1', 'started_at' => now()->subMinutes(2), 'last_seen_at' => now()->subMinute()]);
    $session->routines()->attach([
        GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Outside the US wait'])->id,
        GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Outdated wait'])->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="gym-routines"')
        ->assertSee('Routines: Outside the US wait, Outdated wait');
});

test('the search\'s totals are kept half a minute, so paging and actions reuse them', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(3)->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::activity.index')->call('loadCounts')->assertSee('3 requests found');

    EdgeRequest::factory()->for($domain)->count(2)->create();

    $component->call('$refresh')->assertSee('3 requests found');

    $this->travel(31)->seconds();

    $component->call('$refresh')->assertSee('5 requests found');
});

test('the list shows before the chart and counts, which load right after the page', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(3)->create(['path' => '/listed-first']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSee('/listed-first')
        ->assertSeeHtml('wire:init="loadCounts"')
        ->assertSee('Counting the requests')
        ->assertDontSee('3 requests found')
        ->assertDontSeeHtml('data-test="activity-chart"')
        ->call('loadCounts')
        ->assertSee('3 requests found across 1 website')
        ->assertSeeHtml('data-test="activity-chart"')
        ->assertSeeText('Requests (3)')
        ->assertDontSeeHtml('wire:init="loadCounts"')
        ->set('period', '21d')
        ->assertSee('/listed-first')
        ->assertSeeHtml('wire:init="loadCounts"')
        ->assertDontSee('3 requests found')
        ->call('loadCounts')
        ->assertSee('3 requests found across 1 website');
});
