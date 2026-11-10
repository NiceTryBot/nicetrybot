<?php

namespace NiceTry\WhiteList\Support;

use App\Support\AllowedAddressSource;
use Illuminate\Support\Facades\Cache;
use NiceTry\WhiteList\Models\WhitelistEntry;

/**
 * The White List's part of the installation's allow list: every range of every enabled entry. Kept a
 * few seconds (and dropped whenever an entry changes), since it's read on the gym check and the tarpit
 * and blackhole feeds.
 */
class WhitelistAllowed implements AllowedAddressSource
{
    /**
     * How long the gathered ranges are kept between reads, in seconds.
     */
    private const int CACHE_SECONDS = 10;

    public function allowedRanges(): array
    {
        return Cache::remember(WhitelistEntry::RANGES_CACHE_KEY, self::CACHE_SECONDS, fn () => WhitelistEntry::query()
            ->enabled()
            ->pluck('ranges')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all());
    }
}
