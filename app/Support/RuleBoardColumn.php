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
     * @param  int  $order  Left to right, from what asks least of a client to what asks most.
     * @param  string  $tone  One of the tones the board draws: zinc, teal, amber, violet, rose or ink.
     * @param  bool  $showWhenEmpty  Keep the column on the board when no rule is in it.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $icon,
        public int $order,
        public string $tone = 'zinc',
        public bool $showWhenEmpty = true,
    ) {
        //
    }
}
