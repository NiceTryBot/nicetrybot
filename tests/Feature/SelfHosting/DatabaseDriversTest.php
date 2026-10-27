<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
| A stack with no Redis at all runs the cache, queue and sessions on Laravel's database drivers
| (CACHE_STORE, QUEUE_CONNECTION and SESSION_DRIVER = database).
*/

test('the tables the database drivers use come with the migrations', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions']);

test('the database cache store gives out locks one holder at a time', function () {
    config(['cache.default' => 'database']);

    $lock = Cache::lock('self-hosting:test', 30);

    expect($lock->get())->toBeTrue()
        ->and(Cache::lock('self-hosting:test', 30)->get())->toBeFalse()
        ->and(DB::table('cache_locks')->count())->toBe(1);

    $lock->release();

    expect(Cache::lock('self-hosting:test', 30)->get())->toBeTrue();
});

test('on the database store, a scheduled command still running holds back the next run', function () {
    config(['cache.default' => 'database']);

    $event = app(Schedule::class)->command('edge:import-logs')->withoutOverlapping(5);

    expect($event->mutex->create($event))->toBeTrue()
        ->and($event->mutex->exists($event))->toBeTrue()
        ->and($event->mutex->create($event))->toBeFalse();

    $event->mutex->forget($event);

    expect($event->mutex->exists($event))->toBeFalse();
});

test('jobs wait in the jobs table on the database connection', function () {
    Queue::connection('database')->push(CallQueuedClosure::create(fn () => null));

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(Queue::connection('database')->size())->toBe(1);
});
