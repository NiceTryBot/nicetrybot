<?php

namespace NiceTry\TrafficMap\Data;

/**
 * A primary group in a two-level treemap: a rectangle that holds its secondary tiles (or stands solid
 * when it is too small to subdivide).
 */
class TreemapBlock
{
    /**
     * @param  list<TreemapTile>  $tiles  leaves, already placed in absolute map coordinates
     */
    public function __construct(
        public readonly string $key,
        public readonly string $sym,
        public readonly int $requests,
        public readonly int $suspicious,
        public readonly float $x,
        public readonly float $y,
        public readonly float $w,
        public readonly float $h,
        public readonly string $color,
        public readonly string $size,
        public readonly bool $subdivided,
        public readonly array $tiles,
        public readonly ?TreemapClient $client = null,
    ) {}

    public function suspectPct(): int
    {
        return $this->requests > 0 ? (int) round($this->suspicious / $this->requests * 100) : 0;
    }
}
