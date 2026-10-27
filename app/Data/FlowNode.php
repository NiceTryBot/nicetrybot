<?php

namespace App\Data;

/**
 * One stage value in a traffic flow, e.g. a network, a fingerprint or a path.
 */
readonly class FlowNode
{
    /**
     * @param  string  $key  Identifies the value within its stage.
     * @param  array<string, int|string>|null  $filter  The Activity filter that shows this value's requests.
     * @param  string|null  $detail  Extra context for the tooltip, e.g. the full user agent.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?array $filter = null,
        public ?string $detail = null,
    ) {
        //
    }
}
