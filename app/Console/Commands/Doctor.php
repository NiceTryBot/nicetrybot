<?php

namespace App\Console\Commands;

use App\Models\EdgeRequest;
use App\Support\Releases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

#[Signature('nicetry:doctor')]
#[Description('Check the app\'s own health: the database, the cache, the queue, failed jobs and the edge\'s log imports (nicetrybot doctor runs it on a self-hosted server)')]
class Doctor extends Command
{
    /**
     * A database or cache round trip slower than this (milliseconds) is worth a look.
     */
    private const int SLOW_MS = 100;

    /**
     * More jobs than this waiting means the worker is behind.
     */
    private const int QUEUE_BACKLOG = 200;

    /**
     * The newest imported request older than this (minutes), while the edge saw traffic today, means imports have stopped.
     */
    private const int IMPORT_STALE_MINUTES = 15;

    private bool $failed = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->checkDatabase();
        $this->checkCache();
        $this->checkQueue();

        // Both read the database; when it's unreachable, its own line has said so.
        if (! $this->failed) {
            $this->checkFailedJobs();
            $this->checkImports();
        }

        $this->checkRelease();

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        try {
            $milliseconds = $this->time(fn () => DB::select('select 1'));
            $size = DB::getDriverName() === 'pgsql'
                ? ' · '.Str::of((string) DB::scalar('select pg_size_pretty(pg_database_size(current_database()))'))->replace(' ', '')
                : '';

            $this->report($milliseconds > self::SLOW_MS ? 'warn' : 'ok', 'Database', $milliseconds.' ms'.$size, $milliseconds > self::SLOW_MS ? 'slow to answer' : '');
        } catch (Throwable $e) {
            $this->report('fail', 'Database', 'unreachable', Str::limit(Str::squish($e->getMessage()), 120));
        }
    }

    private function checkCache(): void
    {
        try {
            $key = 'nicetry:doctor:'.Str::random(8);
            $milliseconds = $this->time(function () use ($key): void {
                Cache::put($key, 1, 10);
                Cache::get($key);
                Cache::forget($key);
            });

            $this->report($milliseconds > self::SLOW_MS ? 'warn' : 'ok', 'Cache', $milliseconds.' ms ('.config('cache.default').')', $milliseconds > self::SLOW_MS ? 'slow to answer' : '');
        } catch (Throwable $e) {
            $this->report('fail', 'Cache', 'unreachable', Str::limit(Str::squish($e->getMessage()), 120));
        }
    }

    private function checkQueue(): void
    {
        try {
            $waiting = Queue::size();

            $this->report($waiting > self::QUEUE_BACKLOG ? 'warn' : 'ok', 'Queue', $waiting.' waiting ('.config('queue.default').')', $waiting > self::QUEUE_BACKLOG ? 'the worker is behind' : '');
        } catch (Throwable $e) {
            $this->report('fail', 'Queue', 'unreachable', Str::limit(Str::squish($e->getMessage()), 120));
        }
    }

    private function checkFailedJobs(): void
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($table)) {
            return;
        }

        $failed = DB::table($table)->where('failed_at', '>=', now()->subDay())->count();

        $this->report($failed > 0 ? 'warn' : 'ok', 'Failed jobs', $failed.' in the last 24 hours', $failed > 0 ? 'see them: php artisan queue:failed' : '');
    }

    /**
     * The edge's lines reach the app every minute or so; the newest one tells whether that's still happening.
     */
    private function checkImports(): void
    {
        $newest = EdgeRequest::query()->max('occurred_at');

        if ($newest === null) {
            $this->report('ok', 'Edge imports', 'no requests yet', 'they arrive once a domain points at the edge');

            return;
        }

        $minutes = (int) now()->diffInMinutes($newest, true);
        $lastHour = EdgeRequest::query()->where('occurred_at', '>=', now()->subHour())->count();
        $stale = $minutes > self::IMPORT_STALE_MINUTES && EdgeRequest::query()->where('occurred_at', '>=', now()->subDay())->exists();

        $this->report(
            $stale ? 'warn' : 'ok',
            'Edge imports',
            sprintf('newest %s ago · %s in the last hour', $minutes < 1 ? 'under a minute' : $minutes.' min', number_format($lastHour)),
            $stale ? 'nothing new for a while: nicetrybot logs vector' : '',
        );
    }

    private function checkRelease(): void
    {
        $current = Releases::current();

        if ($current === null) {
            return;
        }

        $newer = Releases::newer();

        $this->report($newer === null ? 'ok' : 'warn', 'Release', $current, $newer === null ? '' : $newer['version'].' is out: nicetrybot update');
    }

    /**
     * How long a callback takes, in whole milliseconds.
     */
    private function time(callable $callback): int
    {
        $started = hrtime(true);
        $callback();

        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * One line: a mark, the check, what it found, and what to do about it.
     */
    private function report(string $status, string $check, string $value, string $advice = ''): void
    {
        $mark = match ($status) {
            'ok' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>!</>',
            default => '<fg=red>✗</>',
        };

        if ($status === 'fail') {
            $this->failed = true;
        }

        $this->line(sprintf('  %s %-14s %s%s', $mark, $check, $value, $advice === '' ? '' : '   <fg=gray>'.$advice.'</>'));
    }
}
