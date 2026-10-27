<?php

namespace App\Support;

use App\Models\Team;
use App\Models\VisitorDataSetting;
use Carbon\CarbonImmutable;

/**
 * Something that keeps visitors' data in its own tables: the core, or a package (tagged with
 * VisitorDataHolders::TAG). Each holder deletes its own data when the team's retention runs out and
 * when a visitor is erased, so a package's tables are looked after without the core knowing them.
 */
interface VisitorDataHolder
{
    /**
     * The retention periods the holder's data is kept for, which teams choose on the Visitor data page.
     *
     * @return list<RetentionPeriod>
     */
    public function retention(): array;

    /**
     * Delete the team's data that's older than its retention allows, stopping once the deadline
     * (a microtime) passes; the next run carries on.
     *
     * @return array<string, int> Rows deleted, by kind of data.
     */
    public function prune(Team $team, VisitorDataSetting $retention, CarbonImmutable $now, float $deadline): array;

    /**
     * Delete everything the team holds about one visitor: an IP address, a JA4 TLS fingerprint, or both
     * (anything matching either).
     *
     * @return array<string, int> Rows deleted, by kind of data.
     */
    public function forget(Team $team, ?string $ip, ?string $ja4): array;
}
