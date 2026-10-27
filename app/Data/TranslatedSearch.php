<?php

namespace App\Data;

use App\Enums\TrafficPeriod;

/**
 * A plain-English question turned into an Activity search (a WHERE, maybe with GROUP BY and HAVING), and
 * what it asks for that the search can't say: the period, the client filter ("spoofed", "outdated" or ""
 * for any), and whether to list "requests", "clients" or "paths".
 */
readonly class TranslatedSearch
{
    public function __construct(
        public string $search,
        public ?TrafficPeriod $period = null,
        public ?string $note = null,
        public string $client = '',
        public string $view = 'requests',
    ) {
        //
    }
}
