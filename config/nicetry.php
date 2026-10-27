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

];
