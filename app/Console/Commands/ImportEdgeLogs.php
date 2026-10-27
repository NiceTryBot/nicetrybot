<?php

namespace App\Console\Commands;

use App\Actions\Edge\ImportPendingEdgeLogFiles;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edge:import-logs {--since= : Import files from this time on (e.g. "2026-10-03 18:00"), to backfill or replay}')]
#[Description('Import the request logs the edge nodes have shipped to S3')]
class ImportEdgeLogs extends Command
{
    /**
     * Seconds after which no new file is started, leaving headroom in the scheduler's 70-second Lambda timeout.
     */
    private const int TIME_BUDGET_SECONDS = 45;

    /**
     * Determine if the edge ships its logs to a bucket. Without one (EDGE_LOGS_BUCKET empty, as on a
     * self-hosted stack by default) every line reaches the app through /caddy/ingest instead.
     */
    public static function hasBucket(): bool
    {
        return filled(config('filesystems.disks.edge-logs.bucket'));
    }

    /**
     * Execute the console command.
     */
    public function handle(ImportPendingEdgeLogFiles $importPendingEdgeLogFiles): int
    {
        if (! self::hasBucket()) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        $since = $this->option('since') ? CarbonImmutable::parse($this->option('since'), 'UTC') : null;

        $files = $importPendingEdgeLogFiles->handle($now, deadline: $now->addSeconds(self::TIME_BUDGET_SECONDS), since: $since);

        $this->info(sprintf(
            'Imported %d file(s): %d request(s), %d skipped.',
            $files->count(),
            $files->sum('rows_imported'),
            $files->sum('rows_skipped'),
        ));

        return self::SUCCESS;
    }
}
