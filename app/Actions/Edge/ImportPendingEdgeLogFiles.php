<?php

namespace App\Actions\Edge;

use App\Models\EdgeLogFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ImportPendingEdgeLogFiles
{
    /**
     * How far back to look for files when nothing has been imported yet.
     */
    private const int FIRST_RUN_LOOKBACK_HOURS = 24;

    public function __construct(private ImportEdgeLogFile $importEdgeLogFile)
    {
        //
    }

    /**
     * Import every batch file the edge nodes have shipped since the last run, oldest first.
     *
     * Files live under raw/dt=YYYY-MM-DD/hh=HH/. Listing restarts one hour before the hour of the
     * newest imported file, so a file a slower node uploads late is still picked up, and keys that
     * were already imported are skipped. No new file is started once the deadline has passed, so a
     * run fits inside the scheduler's Lambda timeout and the next run continues where it stopped.
     * With $since, listing starts from that hour instead, to backfill or replay older files.
     *
     * @return Collection<int, EdgeLogFile>
     */
    public function handle(CarbonImmutable $now, ?CarbonImmutable $deadline = null, ?CarbonImmutable $since = null): Collection
    {
        $imported = new Collection;

        foreach ($this->pendingKeys($now, $since) as $key) {
            if ($deadline !== null && CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $imported->push($this->importEdgeLogFile->handle($key));
        }

        return $imported;
    }

    /**
     * List the keys of files that haven't been imported yet, oldest first.
     *
     * @return list<string>
     */
    private function pendingKeys(CarbonImmutable $now, ?CarbonImmutable $since): array
    {
        $disk = Storage::disk('edge-logs');
        $keys = [];

        for ($hour = $since?->utc()->startOfHour() ?? $this->firstHourToList($now); $hour->lessThanOrEqualTo($now); $hour = $hour->addHour()) {
            array_push($keys, ...$disk->files('raw/dt='.$hour->format('Y-m-d').'/hh='.$hour->format('H')));
        }

        if ($keys === []) {
            return [];
        }

        $alreadyImported = EdgeLogFile::query()->whereIn('key', $keys)->pluck('key')->all();

        $pending = array_values(array_diff($keys, $alreadyImported));
        sort($pending);

        return $pending;
    }

    /**
     * Get the first hourly prefix to list: one hour before the newest imported file, or the
     * first-run lookback when nothing has been imported yet.
     */
    private function firstHourToList(CarbonImmutable $now): CarbonImmutable
    {
        $newestKey = EdgeLogFile::query()->max('key');

        if (is_string($newestKey) && preg_match('#^raw/dt=(\d{4}-\d{2}-\d{2})/hh=(\d{2})/#', $newestKey, $matches) === 1) {
            return CarbonImmutable::parse("{$matches[1]} {$matches[2]}:00:00", 'UTC')->subHour();
        }

        return $now->subHours(self::FIRST_RUN_LOOKBACK_HOURS)->startOfHour();
    }
}
