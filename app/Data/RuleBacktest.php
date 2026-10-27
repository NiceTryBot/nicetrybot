<?php

namespace App\Data;

/**
 * What a rule would have done over a past period, without doing it.
 */
readonly class RuleBacktest
{
    /**
     * @param  list<array{client_ip: ?string, ja4: ?string, network: ?string, is_hosting: bool, country: ?string, requests: int, hosts: int, first_seen: string, last_seen: string}>  $subjects  The busiest matched subjects.
     */
    public function __construct(
        public int $days,
        public int $subjectCount,
        public int $requestCount,
        public int $outsideHostingCount,
        public array $subjects,
    ) {
        //
    }

    /**
     * Determine if the rule would have matched nothing.
     */
    public function isEmpty(): bool
    {
        return $this->subjectCount === 0;
    }
}
