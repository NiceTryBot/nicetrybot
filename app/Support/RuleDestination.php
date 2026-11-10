<?php

namespace App\Support;

/**
 * Somewhere a rule sends the clients it flags.
 */
final readonly class RuleDestination
{
    /**
     * @param  string|null  $column  The board column it puts the rule in. Null for a link that doesn't act on the client (a routine that's off, a bucket reading the rule): it shows on the rule's cards, and leaves the rule where it is.
     * @param  string|null  $note  A short qualifier, like the domains it skips.
     */
    public function __construct(
        public ?string $column,
        public string $label,
        public string $icon,
        public ?string $url = null,
        public ?string $note = null,
    ) {
        //
    }
}
