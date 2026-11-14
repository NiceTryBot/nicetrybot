<?php

namespace App\Support;

/**
 * A rule a filter stands for that the team doesn't have yet, such as one of a rule pack's rules it
 * hasn't installed, drawn on the Rules page as a card it can add.
 */
readonly class RuleFilterCandidate
{
    /**
     * @param  string  $key  The candidate's key, unique within what the filter's value stands for.
     * @param  string  $name  Translated.
     * @param  string  $blurb  Translated.
     * @param  string|null  $unavailable  Why it can't be added now (e.g. it needs an add-on), or null.
     * @param  string|null  $column  The board column it acts in once added (App\Support\RuleBoardColumn's
     *                               key), or null for Tag only.
     * @param  string|null  $destination  Where it sends clients once added (a routine's name), translated.
     * @param  string|null  $confirm  What to ask before adding it, when adding it acts on clients at once.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $blurb,
        public string $source,
        public ?string $unavailable = null,
        public ?string $column = null,
        public ?string $destination = null,
        public ?string $confirm = null,
    ) {
        //
    }
}
