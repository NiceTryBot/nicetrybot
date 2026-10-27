<?php

use App\Models\Domain;
use App\Models\EdgeLogFile;
use App\Models\EdgeRequest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/**
 * The environment variables the edge-logs disk reads, all unset.
 *
 * @return array<string, null>
 */
function unsetEdgeLogsEnv(): array
{
    return array_fill_keys(['EDGE_LOGS_BUCKET', 'EDGE_LOGS_REGION', 'EDGE_LOGS_ENDPOINT', 'EDGE_LOGS_KEY', 'EDGE_LOGS_SECRET', 'EDGE_LOGS_PATH_STYLE'], null);
}

/**
 * Find the scheduled edge:import-logs run.
 */
function importEdgeLogsEvent(): Event
{
    return collect(app(Schedule::class)->events())->sole(fn (Event $event) => str_contains((string) $event->command, 'edge:import-logs'));
}

test('without a bucket, edge:import-logs does nothing, quietly', function () {
    Storage::fake('edge-logs');
    Storage::disk('edge-logs')->put('raw/dt=2026-10-03/hh=21/node-1.ndjson.gz', gzencode(json_encode(['host' => 'claims.example.com'])));
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    config(['filesystems.disks.edge-logs.bucket' => '']);

    $this->artisan('edge:import-logs')->doesntExpectOutput()->assertSuccessful();

    expect(EdgeLogFile::count())->toBe(0)
        ->and(EdgeRequest::count())->toBe(0);
});

test('the scheduler runs edge:import-logs only when there is a bucket', function () {
    config(['filesystems.disks.edge-logs.bucket' => 'example-edge-logs']);
    expect(importEdgeLogsEvent()->filtersPass($this->app))->toBeTrue();

    config(['filesystems.disks.edge-logs.bucket' => '']);
    expect(importEdgeLogsEvent()->filtersPass($this->app))->toBeFalse();
});

test('the edge-logs disk has no bucket, and AWS\'s default region, when nothing is set', function () {
    expect(configFileWith('filesystems.php', unsetEdgeLogsEnv())['disks']['edge-logs'])->toBe([
        'driver' => 's3',
        'key' => null,
        'secret' => null,
        'region' => 'us-east-1',
        'bucket' => '',
        'endpoint' => null,
        'use_path_style_endpoint' => false,
        'throw' => true,
        'report' => true,
    ]);
});

test('an empty bucket means there is none', function () {
    expect(configFileWith('filesystems.php', [...unsetEdgeLogsEnv(), 'EDGE_LOGS_BUCKET' => ''])['disks']['edge-logs']['bucket'])->toBe('');
});

test('the edge-logs disk takes an S3-compatible store from the environment, path-style by default', function () {
    $disk = configFileWith('filesystems.php', [
        ...unsetEdgeLogsEnv(),
        'EDGE_LOGS_BUCKET' => 'example-edge-logs',
        'EDGE_LOGS_REGION' => 'nyc3',
        'EDGE_LOGS_ENDPOINT' => 'https://nyc3.digitaloceanspaces.com',
        'EDGE_LOGS_KEY' => 'DO00EXAMPLEKEY',
        'EDGE_LOGS_SECRET' => 'example-secret',
    ])['disks']['edge-logs'];

    expect($disk)->toMatchArray([
        'key' => 'DO00EXAMPLEKEY',
        'secret' => 'example-secret',
        'region' => 'nyc3',
        'bucket' => 'example-edge-logs',
        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'use_path_style_endpoint' => true,
    ]);

    $client = Storage::build($disk)->getClient();

    expect((string) $client->getEndpoint())->toBe('https://nyc3.digitaloceanspaces.com')
        ->and($client->getRegion())->toBe('nyc3')
        ->and($client->getCredentials()->wait()->getAccessKeyId())->toBe('DO00EXAMPLEKEY');

    expect(configFileWith('filesystems.php', [...unsetEdgeLogsEnv(), 'EDGE_LOGS_ENDPOINT' => 'https://example.r2.cloudflarestorage.com', 'EDGE_LOGS_PATH_STYLE' => 'false'])['disks']['edge-logs'])
        ->use_path_style_endpoint->toBeFalse();

    expect(configFileWith('filesystems.php', [...unsetEdgeLogsEnv(), 'EDGE_LOGS_PATH_STYLE' => 'true', 'EDGE_LOGS_REGION' => ''])['disks']['edge-logs'])
        ->use_path_style_endpoint->toBeTrue()
        ->region->toBe('us-east-1');
});
