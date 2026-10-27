<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'nicetry_edge' => [
        'token' => env('NICETRY_EDGE_TOKEN'),
    ],

    /*
     * Claude, called with each user's own API key (Settings → Artificial Intelligence) to turn a
     * plain-English Activity search into the rule language.
     */
    'anthropic' => [
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com'),
        'search_model' => env('ANTHROPIC_SEARCH_MODEL', 'claude-opus-5'),
        'search_effort' => env('ANTHROPIC_SEARCH_EFFORT', 'low'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
