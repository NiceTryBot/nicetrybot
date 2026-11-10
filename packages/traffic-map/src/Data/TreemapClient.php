<?php

namespace NiceTry\TrafficMap\Data;

/**
 * The client IP a tile or block stands for, and the network it comes from if known.
 */
class TreemapClient
{
    public function __construct(
        public readonly string $ip,
        public readonly ?int $asn,
        public readonly ?string $network,
        public readonly ?string $category,
    ) {}

    /**
     * The network in one line, with its name last, since a narrow tile cuts off the end.
     */
    public function networkLine(): ?string
    {
        if ($this->asn === null) {
            return null;
        }

        return implode(' · ', array_filter(['AS'.$this->asn, $this->category, $this->network]));
    }
}
