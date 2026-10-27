<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Who may tell the app, in X-Forwarded-For, -Proto, -Host and -Port, who the visitor is and how
    | they arrived: a comma list of addresses or CIDR ranges, or * for whoever connects. Laravel's
    | TrustProxies middleware reads it on every request. Unset trusts no one, as on Vapor.
    |
    | A self-hosted stack sets the private ranges, because only its own containers can reach the
    | app (nothing publishes its port) and the edge is the one in front of it: the visitor's IP then
    | drives login throttling, and https from the edge makes the app's URLs and cookies secure.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
