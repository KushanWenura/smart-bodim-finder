<?php

return [

    'smart_bodim_ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:5100'),
        'secret' => env('AI_INTERNAL_SECRET', 'change-this-in-every-shared-environment'),
        'timeout' => env('AI_TIMEOUT_SECONDS', 1.5),
    ],

    'routing' => [
        // Optional OSRM-compatible base URL. Empty keeps the complete offline fallback.
        'url' => env('ROUTING_SERVICE_URL', ''),
        'timeout' => env('ROUTING_TIMEOUT_SECONDS', 1.2),
    ],

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
