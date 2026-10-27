<?php

namespace App\Data;

use App\Enums\TrafficPeriod;

readonly class TrafficSummary
{
    /**
     * @param  array<int, array{key: string, label: string, start: int, end: int, humans: int, suspicious: int, notFound: int}>  $buckets
     */
    public function __construct(
        public TrafficPeriod $period,
        public int $requests,
        public int $suspicious,
        public int $notFound,
        public int $keptOffOrigin,
        public int $gymMilliseconds,
        public int $uniqueIps,
        public int $previousRequests,
        public int $previousSuspicious,
        public int $previousNotFound,
        public int $previousKeptOffOrigin,
        public array $buckets,
    ) {
        //
    }

    /**
     * Determine if no requests were logged in the period.
     */
    public function isEmpty(): bool
    {
        return $this->requests === 0;
    }

    /**
     * Get the percentage of requests tagged as suspicious.
     */
    public function suspiciousShare(): float
    {
        return $this->requests === 0 ? 0.0 : round($this->suspicious / $this->requests * 100, 1);
    }

    /**
     * Get the percentage change between a current and previous value, or null when there is nothing to compare.
     */
    public function change(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Get the largest bucket total, used to scale the chart.
     */
    public function largestBucket(): int
    {
        return (int) collect($this->buckets)->max(fn (array $bucket) => $bucket['humans'] + $bucket['suspicious']);
    }

    /**
     * Get the bucket with the most suspicious traffic, if any was tagged.
     *
     * @return array{key: string, label: string, start: int, end: int, humans: int, suspicious: int, notFound: int}|null
     */
    public function busiestSuspiciousBucket(): ?array
    {
        $bucket = collect($this->buckets)->sortByDesc('suspicious')->first();

        return $bucket && $bucket['suspicious'] > 0 ? $bucket : null;
    }

    /**
     * Get the time scanners spent in the gym, like "86h 42m".
     */
    public function gymTime(): string
    {
        $minutes = intdiv($this->gymMilliseconds, 60_000);

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }
}
