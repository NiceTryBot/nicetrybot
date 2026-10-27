<?php

use App\Actions\Edge\ImportPendingEdgeLogFiles;
use App\Models\Domain;
use App\Models\EdgeLogFile;
use App\Models\EdgeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // A bucket to import from (there's none by default).
    config(['filesystems.disks.edge-logs.bucket' => 'example-edge-logs']);
    Storage::fake('edge-logs');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 21:30:00', 'UTC'));
});

/**
 * Store a batch file holding one request for claims.example.com.
 */
function putOneRequestEdgeLogFile(string $key): void
{
    Storage::disk('edge-logs')->put($key, gzencode(json_encode([
        'occurred_at' => '2026-10-03T21:00:00Z',
        'host' => 'claims.example.com',
        'client_ip' => '203.0.113.7',
        'method' => 'GET',
        'path' => '/',
        'status' => 200,
        'duration_ms' => 40,
        'action' => 'passed',
    ])));
}

test('the first run imports files from the last 24 hours', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1-a.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=20/node-1-b.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-02/hh=20/node-1-too-old.ndjson.gz');

    $this->artisan('edge:import-logs')
        ->expectsOutputToContain('Imported 2 file(s): 2 request(s), 0 skipped.')
        ->assertSuccessful();

    expect(EdgeLogFile::pluck('key')->sort()->values()->all())->toBe([
        'raw/dt=2026-10-03/hh=20/node-1-b.ndjson.gz',
        'raw/dt=2026-10-03/hh=21/node-1-a.ndjson.gz',
    ]);
});

test('later runs resume an hour before the newest imported file and pick up late uploads', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    EdgeLogFile::factory()->create(['key' => 'raw/dt=2026-10-03/hh=19/node-1-imported.ndjson.gz']);
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=19/node-1-imported.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=17/node-2-before-window.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=18/node-2-late.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1-new.ndjson.gz');

    $this->artisan('edge:import-logs')->assertSuccessful();

    expect(EdgeLogFile::pluck('key')->sort()->values()->all())->toBe([
        'raw/dt=2026-10-03/hh=18/node-2-late.ndjson.gz',
        'raw/dt=2026-10-03/hh=19/node-1-imported.ndjson.gz',
        'raw/dt=2026-10-03/hh=21/node-1-new.ndjson.gz',
    ])->and(EdgeRequest::count())->toBe(2);
});

test('no new file is started once the deadline has passed', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1-a.ndjson.gz');

    $files = app(ImportPendingEdgeLogFiles::class)->handle(CarbonImmutable::now(), deadline: CarbonImmutable::now());

    expect($files)->toBeEmpty()
        ->and(EdgeLogFile::count())->toBe(0);
});

test('--since backfills files older than the usual lookback', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    putOneRequestEdgeLogFile('raw/dt=2026-10-01/hh=09/node-1-old.ndjson.gz');
    putOneRequestEdgeLogFile('raw/dt=2026-10-03/hh=21/node-1-new.ndjson.gz');

    $this->artisan('edge:import-logs', ['--since' => '2026-10-01 09:30'])
        ->expectsOutputToContain('Imported 2 file(s)')
        ->assertSuccessful();
});
