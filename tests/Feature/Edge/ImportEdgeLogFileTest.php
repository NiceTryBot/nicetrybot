<?php

use App\Actions\Edge\ImportEdgeLogFile;
use App\Enums\ClientBrowser;
use App\Enums\ClientPlatform;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\TlsStack;
use App\Models\ClientHint;
use App\Models\Domain;
use App\Models\EdgeLogFile;
use App\Models\EdgeRequest;
use App\Models\GymSession;
use App\Models\LivewireComponent;
use App\Models\Location;
use App\Models\Network;
use App\Models\RequestPath;
use App\Models\UserAgent;
use App\Support\CountryCentroids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('edge-logs');
});

/**
 * Build one log line in the shape the edge node's Vector config writes.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function edgeLogLine(array $overrides = []): array
{
    return array_merge([
        'occurred_at' => '2026-10-03T21:39:28.512Z',
        'host' => 'claims.example.com',
        'client_ip' => '203.0.113.7',
        'method' => 'GET',
        'path' => '/.env',
        'status' => 404,
        'duration_ms' => 12.6,
        'user_agent' => 'python-requests/2.32.3',
        'action' => 'observed',
        'bot_tag' => 'probe',
        'ja3' => '375c6162a492dfbf2795909110ce8424',
        'ja4' => 't13d4907h2_0d8feac7bc37_7395dae3b2f3',
        'country_code' => 'CA',
        'region' => 'Quebec',
        'city' => 'Montreal',
        'asn' => 16276,
        'as_org' => 'OVH SAS',
    ], $overrides);
}

/**
 * Store a gzip-compressed batch file on the fake edge-logs disk.
 *
 * @param  list<array<string, mixed>|string>  $lines
 */
function putEdgeLogFile(string $key, array $lines, bool $compressed = true): void
{
    $body = collect($lines)
        ->map(fn (array|string $line) => is_string($line) ? $line : json_encode($line))
        ->implode("\n");

    Storage::disk('edge-logs')->put($key, $compressed ? gzencode($body) : $body);
}

test('each log line becomes an edge request on its domain', function () {
    $domain = Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine()]);

    $file = app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    $request = EdgeRequest::sole();

    expect($request->domain_id)->toBe($domain->id)
        ->and($request->occurred_at->toDateTimeString())->toBe('2026-10-03 21:39:28')
        ->and($request->client_ip)->toBe('203.0.113.7')
        ->and($request->method)->toBe(HttpMethod::Get)
        ->and($request->path)->toBe('/.env')
        ->and($request->status_code)->toBe(404)
        ->and($request->duration_ms)->toBe(13)
        ->and($request->action)->toBe(RequestAction::Observed)
        ->and($request->bot_tag)->toBe('probe')
        ->and($request->ja3)->toBe('375c6162a492dfbf2795909110ce8424')
        ->and($request->ja4)->toBe('t13d4907h2_0d8feac7bc37_7395dae3b2f3')
        ->and($file->rows_imported)->toBe(1)
        ->and($file->rows_skipped)->toBe(0);
});

test('a request to a domain\'s preview is the domain\'s', function () {
    $domain = Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(['host' => 'claims-example-com.preview.example'])]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::sole()->domain_id)->toBe($domain->id);
});

test('lines for unknown hosts and lines that cannot be parsed are skipped', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [
        edgeLogLine(),
        edgeLogLine(['host' => 'not-a-customer.example.org']),
        'this is not json',
        edgeLogLine(['path' => null]),
        edgeLogLine(['occurred_at' => 'not a date']),
        edgeLogLine(['client_ip' => 'not-an-ip']),
    ]);

    $file = app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::count())->toBe(1)
        ->and($file->rows_imported)->toBe(1)
        ->and($file->rows_skipped)->toBe(5);
});

test('importing the same file twice does not duplicate requests', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(), edgeLogLine()]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');
    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::count())->toBe(2)
        ->and(EdgeLogFile::count())->toBe(1);
});

test('a file the storage client already decompressed is still imported', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine()], compressed: false);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::count())->toBe(1);
});

test('a missing or unknown action is recorded as passed', function (?string $action) {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(['action' => $action, 'bot_tag' => null])]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::sole()->action)->toBe(RequestAction::Passed);
})->with([
    'missing' => [null],
    'unknown' => ['teleported'],
]);

test('the network and location the edge looked up are stored with the request', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine()]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    $request = EdgeRequest::sole();

    expect($request->asn)->toBe(16276)
        ->and($request->network->name)->toBe('OVH SAS')
        ->and($request->network->kind)->toBe(NetworkKind::Hosting)
        ->and($request->location->label())->toBe('Montreal, Quebec, CA');
});

test('a request with no network or location data is still imported', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(['asn' => null, 'as_org' => null, 'country_code' => null, 'region' => null, 'city' => null])]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    $request = EdgeRequest::sole();

    expect($request->asn)->toBeNull()
        ->and($request->location_id)->toBeNull();
});

test('a location keeps the coordinates it is first seen with, and older ones get them later', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    $older = Location::factory()->create(['country_code' => 'DE', 'region' => 'Hesse', 'city' => 'Frankfurt am Main']);

    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [
        edgeLogLine(['latitude' => 45.5088, 'longitude' => -73.5878]),
        edgeLogLine(['latitude' => 1, 'longitude' => 1]),
        edgeLogLine(['country_code' => 'DE', 'region' => 'Hesse', 'city' => 'Frankfurt am Main', 'latitude' => 50.1155, 'longitude' => 8.68417]),
        edgeLogLine(['country_code' => 'FR', 'region' => '', 'city' => '', 'latitude' => 'nope', 'longitude' => 400]),
    ]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(Location::firstWhere('city', 'Montreal')->coordinates())->toBe([45.5088, -73.5878])
        ->and($older->fresh()->coordinates())->toBe([50.1155, 8.68417])
        ->and(Location::firstWhere('country_code', 'FR'))->latitude->toBeNull()
        ->and(Location::firstWhere('country_code', 'FR')->coordinates())->toBe(CountryCentroids::for('FR'));
});

test('repeated strings are stored once and shared by every request', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(), edgeLogLine(), edgeLogLine(['client_ip' => '198.51.100.4'])]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(EdgeRequest::count())->toBe(3)
        ->and(RequestPath::count())->toBe(1)
        ->and(UserAgent::count())->toBe(1)
        ->and(Network::count())->toBe(1)
        ->and(EdgeRequest::pluck('path_id')->unique())->toHaveCount(1);
});

test('a new path is stored with the category its pattern matches', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [edgeLogLine(['path' => '/.env.local']), edgeLogLine(['path' => '/contact'])]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect(RequestPath::firstWhere('path', '/.env.local')->category)->toBe(PathCategory::ProbeCreds)
        ->and(RequestPath::firstWhere('path', '/contact')->category)->toBeNull();
});

test('the gym\'s own traffic is left out of the site\'s requests', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-07/hh=23/node-1.ndjson.gz', [
        edgeLogLine(['method' => 'POST', 'path' => '/_q/abc123']),
        edgeLogLine(['path' => '/gymbros', 'action' => 'diverted']),
    ]);

    $file = app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-07/hh=23/node-1.ndjson.gz');

    expect($file->rows_imported)->toBe(1)
        ->and($file->rows_skipped)->toBe(1)
        ->and(EdgeRequest::sole()->path)->toBe('/gymbros');
});

test('a request the edge named a Livewire component on is linked to it', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-12/hh=01/node-1.ndjson.gz', [
        edgeLogLine(['method' => 'POST', 'path' => '/livewire/update', 'status' => 200, 'livewire_component' => 'app.filament.auth.login']),
        edgeLogLine(['method' => 'POST', 'path' => '/livewire/update', 'status' => 200]),
    ]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-12/hh=01/node-1.ndjson.gz');

    $login = EdgeRequest::orderBy('id')->value('id');

    expect(DB::table('livewire_calls')->get()->map(fn (object $call) => (array) $call)->all())
        ->toBe([['edge_request_id' => $login, 'livewire_component_id' => LivewireComponent::where('name', 'app.filament.auth.login')->value('id')]]);
});

test('a line\'s header fingerprint, client hints and language are stored', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-08/hh=15/node-1.ndjson.gz', [
        edgeLogLine([
            'ja4h' => 'ge20cn14enus_9e3e4a4b0f5c_000000000000',
            'ch_ua' => '"Chromium";v="142", "HeadlessChrome";v="142"',
            'ch_platform' => '"Linux"',
            'lang' => 'en-US,en;q=0.9',
        ]),
        edgeLogLine(['path' => '/plain']),
    ]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-08/hh=15/node-1.ndjson.gz');

    [$browser, $script] = EdgeRequest::orderBy('id')->get()->all();

    expect($browser)
        ->ja4h->toBe('ge20cn14enus_9e3e4a4b0f5c_000000000000')
        ->client_hints->toBe('"Chromium";v="142", "HeadlessChrome";v="142" | "Linux"')
        ->accept_language->toBe('en-US,en;q=0.9')
        ->and($script)
        ->ja4h->toBeNull()
        ->client_hints->toBeNull()
        ->accept_language->toBeNull();
});

test('a new user agent, JA4 fingerprint and set of client hints are profiled as they are stored', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putEdgeLogFile('raw/dt=2026-10-12/hh=17/node-1.ndjson.gz', [
        edgeLogLine([
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1',
            'ja4' => 't13d1517h2_8daaf6152771_cb7bf5808d99',
            'ch_ua' => '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"',
            'ch_platform' => '"iOS"',
        ]),
    ]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-12/hh=17/node-1.ndjson.gz');

    $request = EdgeRequest::sole();
    $this->assertDatabaseHas('user_agent_profiles', ['user_agent_id' => $request->user_agent_id, 'platform' => ClientPlatform::Ios, 'browser' => ClientBrowser::Chromium, 'major_version' => 152]);
    $this->assertDatabaseHas('tls_fingerprint_profiles', ['tls_fingerprint_id' => $request->ja4_id, 'stack' => TlsStack::Chromium]);
    $this->assertDatabaseCount('tls_fingerprint_profiles', 1);
    $this->assertDatabaseHas('client_hint_profiles', ['client_hint_id' => $request->client_hints_id, 'platform' => ClientPlatform::Ios]);
});

test('a request the edge answered with the gym gives its session the client hints it carried', function () {
    $domain = Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    $scanner = GymSession::factory()->create(['team_id' => $domain->team_id, 'domain_id' => $domain->id, 'client_ip' => '203.0.113.7', 'started_at' => '2026-10-03 21:39:29']);
    $earlier = GymSession::factory()->create(['team_id' => $domain->team_id, 'domain_id' => $domain->id, 'client_ip' => '203.0.113.7', 'started_at' => '2026-10-03 20:00:00']);
    $heard = GymSession::factory()->create(['team_id' => $domain->team_id, 'domain_id' => $domain->id, 'client_ip' => '198.51.100.4', 'started_at' => '2026-10-03 21:39:29', 'client_hints_id' => ClientHint::idFor('"Chromium";v="152" | "Windows"')]);
    putEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', [
        edgeLogLine(['action' => 'diverted', 'ch_ua' => '"Chromium";v="152", "Google Chrome";v="152"', 'ch_platform' => '"iOS"']),
        edgeLogLine(['action' => 'diverted', 'client_ip' => '198.51.100.4', 'ch_ua' => '"Chromium";v="152"', 'ch_platform' => '"Android"']),
        edgeLogLine(['action' => 'passed', 'client_ip' => '203.0.113.7', 'ch_ua' => '"Chromium";v="152"', 'ch_platform' => '"Linux"']),
    ]);

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz');

    expect($scanner->fresh()->clientHint->value)->toBe('"Chromium";v="152", "Google Chrome";v="152" | "iOS"')
        ->and($earlier->fresh()->client_hints_id)->toBeNull()
        ->and($heard->fresh()->clientHint->value)->toBe('"Chromium";v="152" | "Windows"');
});
