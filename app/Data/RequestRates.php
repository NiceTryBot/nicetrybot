<?php

namespace App\Data;

use App\Enums\StatsRange;

readonly class RequestRates
{
    public function __construct(
        public StatsRange $range,
        public RateSeries $perSecond,
        public RateSeries $perMinute,
        public int $lastMinute,
        public int $requests,
    ) {
        //
    }

    /**
     * Determine if no requests were logged in the range.
     */
    public function isEmpty(): bool
    {
        return $this->requests === 0;
    }
}
