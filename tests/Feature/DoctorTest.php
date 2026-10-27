<?php

use App\Models\EdgeRequest;
use Illuminate\Support\Facades\DB;

test('a healthy app passes every check', function () {
    EdgeRequest::factory()->create(['occurred_at' => now()->subSeconds(30)]);

    $this->artisan('nicetry:doctor')
        ->expectsOutputToContain('Database')
        ->expectsOutputToContain('Cache')
        ->expectsOutputToContain('0 waiting')
        ->expectsOutputToContain('0 in the last 24 hours')
        ->expectsOutputToContain('newest under a minute ago · 1 in the last hour')
        ->assertSuccessful();
});

test('imports that stopped while the edge had traffic today are flagged', function () {
    EdgeRequest::factory()->create(['occurred_at' => now()->subHours(2)]);

    $this->artisan('nicetry:doctor')
        ->expectsOutputToContain('nicetrybot logs vector')
        ->assertSuccessful();
});

test('failed jobs from the last day are counted, older ones aren\'t', function () {
    foreach ([now()->subHours(3), now()->subDays(3)] as $failedAt) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => $failedAt,
        ]);
    }

    $this->artisan('nicetry:doctor')
        ->expectsOutputToContain('1 in the last 24 hours')
        ->assertSuccessful();
});

test('a database it can\'t reach fails the check and the command', function () {
    $default = config('database.default');
    config()->set('database.connections.broken', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x', 'connect_timeout' => 1]);
    config()->set('database.default', 'broken');

    $this->artisan('nicetry:doctor')
        ->expectsOutputToContain('unreachable')
        ->assertFailed();

    // The test's own transaction is on the real connection: hand it back before it's rolled back.
    config()->set('database.default', $default);
})->skip(fn () => ! extension_loaded('pdo_pgsql'), 'Needs pdo_pgsql.');
