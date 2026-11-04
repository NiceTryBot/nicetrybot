<?php

namespace NiceTry\TrafficMap\Data;

use NiceTry\TrafficMap\Enums\MapDimension;

/**
 * A whole treemap: the placed tiles (one level) or blocks (two levels) and the totals behind them.
 */
class TreemapData
{
    /**
     * @param  list<TreemapTile>  $tiles  one-level tiles (empty when two-level)
     * @param  list<TreemapBlock>  $blocks  two-level blocks (empty when one-level)
     */
    public function __construct(
        public readonly MapDimension $dimension,
        public readonly ?MapDimension $secondary,
        public readonly array $tiles,
        public readonly array $blocks,
        public readonly int $requests,
        public readonly int $suspicious,
        public readonly int $groups,
    ) {}

    public function twoLevel(): bool
    {
        return $this->secondary !== null && $this->blocks !== [];
    }

    public function isEmpty(): bool
    {
        return $this->tiles === [] && $this->blocks === [];
    }

    public function suspectPct(): int
    {
        return $this->requests > 0 ? (int) round($this->suspicious / $this->requests * 100) : 0;
    }
}
