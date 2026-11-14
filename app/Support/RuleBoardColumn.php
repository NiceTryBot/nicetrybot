<?php

namespace App\Support;

/**
 * A column of the Rules page's board: one thing that can happen to the clients a rule flags.
 */
final readonly class RuleBoardColumn
{
    /**
     * The column for rules nothing acts on.
     */
    public const string TAG_ONLY = 'tag';

    /**
     * The Rules page's tabs a column sits under: what records a client (Tags), what makes a client wait
     * or keeps it out (Timeouts), and what puts a site or URL into a mode (Modes).
     */
    public const string TAB_TAGS = 'tags';

    public const string TAB_TIMEOUTS = 'timeouts';

    public const string TAB_MODES = 'modes';

    /**
     * @param  int  $order  Left to right, from what asks least of a client to what asks most.
     * @param  string  $tone  One of the tones the board draws: zinc, teal, amber, orange, violet, rose, sky or ink.
     * @param  bool  $showWhenEmpty  Keep the column on the board when no rule is in it.
     * @param  string  $tab  The Rules page tab the column sits under: TAB_TAGS, TAB_TIMEOUTS or TAB_MODES.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $icon,
        public int $order,
        public string $tone = 'zinc',
        public bool $showWhenEmpty = true,
        public string $tab = self::TAB_TIMEOUTS,
    ) {
        //
    }
}
