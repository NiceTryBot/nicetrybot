<?php

namespace App\Data;

/**
 * One value of a traffic breakdown (a network, country, user agent or fingerprint) and its counts.
 */
readonly class BreakdownRow
{
    /**
     * @param  list<string>  $patterns  For a path category, the glob patterns that define it.
     * @param  string|null  $rule  For a path category, the edge rule (Caddy matcher) it would become.
     */
    public function __construct(
        public int|string|null $key,
        public string $label,
        public ?string $detail,
        public int $requests,
        public int $suspicious,
        public int $uniqueIps,
        public int $related,
        public bool $isHosting = false,
        public array $patterns = [],
        public ?string $rule = null,
    ) {
        //
    }

    /**
     * Get the row's share of all requests, as a whole percentage.
     */
    public function shareOf(int $total): int
    {
        return $total > 0 ? (int) round($this->requests / $total * 100) : 0;
    }
}
