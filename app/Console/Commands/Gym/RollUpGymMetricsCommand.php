<?php

namespace App\Console\Commands\Gym;

use App\Actions\Gym\RollUpGymMetrics;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gym:roll-up-metrics {--days=7 : How many finished days back to (re)roll, covering the gym page window}')]
#[Description('Summarize each team\'s recent gym sessions into daily metrics, so the gym page reads summaries instead of scanning every session')]
class RollUpGymMetricsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RollUpGymMetrics $rollUp): int
    {
        $days = max(1, (int) $this->option('days'));

        Team::query()
            ->whereHas('gymSessions')
            ->each(function (Team $team) use ($rollUp, $days) {
                $rollUp->backfill($team, $days);
            });

        $this->info("Rolled up the last {$days} days of gym metrics.");

        return self::SUCCESS;
    }
}
