<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The marketplace
    |--------------------------------------------------------------------------
    |
    | Where add-ons are bought and licence keys come from: nicetrybot.com. This
    | installation asks it once a day whether each licence it holds is still
    | good, and the Add-ons page links to it.
    |
    */

    'marketplace' => [
        'url' => rtrim((string) env('NICETRY_MARKETPLACE_URL', 'https://nicetrybot.com'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installing add-ons
    |--------------------------------------------------------------------------
    |
    | The add-ons `php artisan addons:install` keeps installed with Composer:
    | packages from the marketplace's Composer repository, space-separated
    | (nicetry/captchas nicetry/threats, or nicetry/captchas:^1.2 to pin one).
    | The Docker image runs it as it builds when the list isn't empty. The
    | token is the buyer's, from their account on the marketplace; it only
    | ever reaches Composer, through COMPOSER_AUTH.
    |
    | via: how this installation gets its add-ons, for the Add-ons page's
    | instructions. "docker" (the release image sets it): NICETRY_ADDONS in
    | the stack's .env and the installer run again. "composer": required
    | where the code lives, then deployed.
    |
    */

    'install' => [
        'via' => env('NICETRY_INSTALL') === 'docker' ? 'docker' : 'composer',
        'packages' => (string) env('NICETRY_ADDONS', ''),
        'repository' => rtrim((string) (env('NICETRY_COMPOSER_URL') ?: rtrim((string) (env('NICETRY_MARKETPLACE_URL') ?: 'https://nicetrybot.com'), '/').'/composer'), '/'),
        'token' => env('NICETRY_COMPOSER_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Licensing
    |--------------------------------------------------------------------------
    |
    | A paid add-on needs a licence key from the marketplace: one per
    | installation, good for ever, with a year of updates. Keys are signed
    | (Ed25519) and checked here against the marketplace's public key, so a key
    | works without calling anywhere. Without a valid key a paid add-on runs
    | for trial_days from the first request that saw it, then goes off until a
    | key is entered. A key the marketplace revokes turns its add-on off on the
    | next daily check.
    |
    | enforce: true or false. Unset (null) means everywhere but the local and
    | testing environments.
    |
    */

    'licensing' => [
        'public_key' => env('NICETRY_LICENCE_PUBLIC_KEY', 'FLZ4feDa7jT7Rzf/JJqqEEzS3EGbLHn/yiKAElTqR/M='),
        'trial_days' => (int) env('NICETRY_LICENCE_TRIAL_DAYS', 14),
        'enforce' => env('NICETRY_LICENSING_ENFORCE'),
    ],

];
