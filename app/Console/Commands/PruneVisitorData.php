<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\VisitorDataSetting;
use App\Support\VisitorDataHolders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('visitor-data:prune {--seconds= : Stop deleting after this long (default config visitor_data.prune_seconds)}')]
#[Description('Delete visitor data older than each team keeps it (Visitor data page, or config visitor_data.retention)')]
class PruneVisitorData extends Command
{
    public function handle(VisitorDataHolders $holders): int
    {
        $now = CarbonImmutable::now();
        $deadline = microtime(true) + (float) ($this->option('seconds') ?? config('visitor_data.prune_seconds'));
        $totals = [];

        foreach (Team::query()->orderBy('id')->lazyById() as $team) {
            if (microtime(true) >= $deadline) {
                $this->warn('Out of time; the next run carries on.');

                break;
            }

            foreach ($holders->prune($team, VisitorDataSetting::for($team), $now, $deadline) as $kind => $count) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $count;
            }
        }

        $this->info($totals === [] ? 'Nothing past its retention.' : collect($totals)->map(fn (int $count, string $kind) => "{$kind}: {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
