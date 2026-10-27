<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where DNS points
    |--------------------------------------------------------------------------
    |
    | What the Domains and Origins pages tell people to point their DNS at: a CNAME target for
    | subdomains, and the A record addresses for apex domains (which can't use a CNAME). The
    | installer sets both: the server's public IPv4 address, and a hostname that resolves to it.
    | Unset, the hostname is the panel's (NICETRY_PANEL_HOST, else APP_URL's host), and there are
    | no addresses until NICETRY_EDGE_ADDRESSES (a comma list) names them.
    |
    */

    'hostname' => strtolower(trim((string) (env('NICETRY_EDGE_HOSTNAME')
        ?: env('NICETRY_PANEL_HOST')
        ?: parse_url((string) env('APP_URL', ''), PHP_URL_HOST)))),

    'addresses' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('NICETRY_EDGE_ADDRESSES', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | The edge's label
    |--------------------------------------------------------------------------
    |
    | A word or two under "Edge" on the live traffic map, such as where it runs. Empty shows none.
    |
    */

    'label' => trim((string) env('NICETRY_EDGE_LABEL', '')) ?: null,

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | Every domain gets a preview under this zone (shop.example.com → shop-example-com.<zone>), whose
    | wildcard DNS record points at the edge, so a site can be tried through the edge before its
    | own DNS moves. Empty (the default) turns previews off: new domains get none and the domain
    | page hides them.
    |
    */

    'preview_zone' => strtolower(trim((string) env('NICETRY_PREVIEW_ZONE', ''), " .\t\n\r\0\x0B")) ?: null,

    /*
    |--------------------------------------------------------------------------
    | The control panel
    |--------------------------------------------------------------------------
    |
    | The hostname a self-hosted panel answers on, served by its own edge like any site (e.g.
    | panel.example.com). Null when the panel isn't behind an edge of its own.
    |
    */

    'panel_host' => strtolower(trim((string) env('NICETRY_PANEL_HOST', ''))) ?: null,

];
