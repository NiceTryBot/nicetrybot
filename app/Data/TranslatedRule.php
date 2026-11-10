<?php

namespace App\Data;

/**
 * A plain-English request turned into a rule: its name and script (which carries its own destination
 * clauses), and a note when Claude had to leave something out or guess.
 */
readonly class TranslatedRule
{
    public function __construct(
        public string $name,
        public string $source,
        public ?string $note = null,
    ) {
        //
    }
}
