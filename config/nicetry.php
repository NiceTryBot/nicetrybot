<?php

/*
 * Settings an installation chooses for itself. The defaults suit a self-hosted panel: a stranger's
 * control panel shouldn't be open to sign-ups.
 */

return [

    /*
     * Who may create an account on this control panel:
     *   open         anyone
     *   invitations  only an email with a pending team invitation (the default); the first administrator
     *                comes from `php artisan nicetry:install`
     *   closed       nobody: the sign-up page is gone, and accounts come from nicetry:install
     */
    'registration' => env('NICETRY_REGISTRATION', 'invitations'),

    /*
     * Whether a domain's ownership TXT record has to be found in public DNS before the edge serves it.
     * Off takes every check on trust, for a test installation whose domains (like site.localhost) don't
     * resolve publicly.
     */
    'verify_dns' => (bool) env('NICETRY_VERIFY_DNS', true),

    /*
     * Whether a site's origin may be on a private network: 10.x, 192.168.x, a container's name. Off,
     * origins must be on the public internet, so nobody who can add a domain can make the edge reach
     * what only its own network can (a cloud's metadata service, the stack's other containers). Turn it
     * on only for an edge meant to reach origins on its own private network. The edge reads the same
     * setting.
     */
    'allow_private_origins' => (bool) env('NICETRY_ALLOW_PRIVATE_ORIGINS', false),

    /*
     * How many domains this installation protects for free (10 unless set). Each More domains pack
     * from the marketplace adds 100 more (Admin → Add-ons); hostnames behind an Origins origin don't
     * count. "unlimited" turns the limit off, as the hosted service runs.
     */
    'domain_limit' => (static function (): ?int {
        $value = env('NICETRY_DOMAIN_LIMIT');

        if ($value === null || $value === '') {
            return 10;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    })(),

    /*
     * The release this image is (bare, like 1.2.0), which the release workflow builds in as
     * NICETRY_RELEASE. Null when the app runs from source or as the hosted service.
     */
    'release' => env('NICETRY_RELEASE') ?: null,

    /*
     * Whether a self-hosted panel asks once a day for the newest release (`nicetry:check-release`), to
     * show administrators an update notice, and where releases are published: the same
     * NICETRY_RELEASE_BASE the installer downloads from.
     */
    'update_check' => [
        'enabled' => (bool) env('NICETRY_UPDATE_CHECK', true),
        'releases' => rtrim((string) env('NICETRY_RELEASE_BASE', 'https://github.com/NiceTryBot/nicetrybot/releases'), '/'),
    ],

];
