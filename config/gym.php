<?php

use NiceTryBot\Obstacles\BrowserCheck\BrowserCheckObstacle;
use NiceTryBot\Obstacles\ProofOfWork\ProofOfWorkObstacle;
use NiceTryBot\Obstacles\ScreenCode\ScreenCodeObstacle;
use NiceTryBot\Obstacles\WaitingRoom\WaitingRoomObstacle;

return [

    /*
    |--------------------------------------------------------------------------
    | Obstacles
    |--------------------------------------------------------------------------
    |
    | Every obstacle the core gives routines, in the order the obstacle library lists them. Each
    | class implements App\Gym\Contracts\Obstacle and lives in its own directory, <Vendor>/<Name>/,
    | under gym/obstacles, e.g. gym/obstacles/NiceTryBot/ProofOfWork is
    | NiceTryBot\Obstacles\ProofOfWork\ProofOfWorkObstacle. Add-ons list theirs in their provider's
    | $obstacles, and the library shows them after these. The list is explicit on purpose: adding a
    | class to the codebase must never put it in front of visitors by itself. Removing one (or the
    | add-on that brought it) skips it in the routines that use it rather than breaking them.
    |
    */

    'obstacles' => [
        ProofOfWorkObstacle::class,
        WaitingRoomObstacle::class,
        ScreenCodeObstacle::class,
        BrowserCheckObstacle::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path
    |--------------------------------------------------------------------------
    |
    | Where the gym answers. The edge serves it on the customer's own domain, so it must be a
    | prefix no customer site uses, and it stays generic: nothing a visitor sees names NiceTryBot.
    |
    */

    'path' => '_q',

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    |
    | A visitor still training after this many idle minutes has given up. A pass is the signed
    | cookie (GymSession::PASS_COOKIE) a cleared visitor carries back to the edge. The edge checks
    | its HMAC with the pass secret, so set a dedicated one before it ships; until then the app
    | key signs passes.
    |
    */

    'idle_minutes' => 15,

    'pass_secret' => env('GYM_PASS_SECRET'),

];
