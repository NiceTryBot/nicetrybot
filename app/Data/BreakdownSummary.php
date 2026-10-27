<?php

namespace App\Data;

use App\Enums\TrafficBreakdown;
use App\Enums\TrafficPeriod;

readonly class BreakdownSummary
{
    /**
     * @param  list<BreakdownRow>  $rows
     */
    public function __construct(
        public TrafficBreakdown $breakdown,
        public TrafficPeriod $period,
        public int $total,
        public array $rows,
    ) {
        //
    }

    /**
     * Determine if no requests were logged in the period.
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
