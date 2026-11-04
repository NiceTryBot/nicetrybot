<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subdomains per domain
    |--------------------------------------------------------------------------
    |
    | The most subdomains one domain adds on its own. With wildcard DNS, anyone
    | can make up names under it, and each one becomes a domain (and a
    | certificate), so past this many a new name is turned away instead.
    |
    */

    'limit' => (int) env('NICETRY_DYNAMIC_SUBDOMAIN_LIMIT', 100),

];
