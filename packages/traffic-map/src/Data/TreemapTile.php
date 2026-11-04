<?php

namespace NiceTry\TrafficMap\Data;

/**
 * One tile of the treemap: a group of requests, placed and coloured.
 */
class TreemapTile
{
    public function __construct(
        public readonly string $key,
        public readonly string $sym,
        public readonly ?string $sub,
        public readonly int $requests,
        public readonly int $suspicious,
        public readonly float $x,
        public readonly float $y,
        public readonly float $w,
        public readonly float $h,
        public readonly string $color,
        public readonly bool $hot,
        public readonly string $size,
    ) {}

    public function suspectPct(): int
    {
        return $this->requests > 0 ? (int) round($this->suspicious / $this->requests * 100) : 0;
    }

    public function humanPct(): float
    {
        return $this->requests > 0 ? round(($this->requests - $this->suspicious) / $this->requests * 100, 1) : 50.0;
    }
}
