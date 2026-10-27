<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IP Neighborhoods
    |--------------------------------------------------------------------------
    |
    | An enriched client shows how busy its IP is, and its /24, /16 and /8, over the last few hours
    | of the team's traffic. A /24 is "noisy" when many hosts share its requests and this one has
    | only a small part of them; a host is "lone" when it makes most of its /24's requests itself.
    |
    */

    'neighborhood' => [
        'hours' => 24,
        'noisy_min_hosts' => 10,
        'noisy_max_share' => 0.2,
        'lone_min_share' => 0.6,
    ],

];
