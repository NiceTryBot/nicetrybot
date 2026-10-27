<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days each kind of visitor data is kept before visitor-data:prune deletes it, for teams
    | that haven't chosen their own on the Visitor data page. Verdicts count from when they ended
    | (expired or were lifted); a verdict that never ends is kept while it's in force. An add-on's
    | retention periods take their defaults from its own config.
    |
    */

    'retention' => [
        'request_days' => (int) env('VISITOR_DATA_REQUEST_DAYS', 90),
        'gym_days' => (int) env('VISITOR_DATA_GYM_DAYS', 90),
        'verdict_days' => (int) env('VISITOR_DATA_VERDICT_DAYS', 30),
    ],

    /*
    | The longest a team may keep anything, in days.
    */

    'max_days' => 730,

    /*
    | How long one run of visitor-data:prune may spend deleting, in seconds. It runs hourly, so a large
    | backlog is worked through over several runs rather than in one long one.
    */

    'prune_seconds' => (int) env('VISITOR_DATA_PRUNE_SECONDS', 240),

];
