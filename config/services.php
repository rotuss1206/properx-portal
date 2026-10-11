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

    // Transactional mail (Spec §3.4). "POSTMARK_API_TEST" is Postmark's test token:
    // requests are validated but nothing is delivered.
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),

        // Basic auth credentials configured on the Postmark bounce/spam webhook URL.
        'webhook' => [
            'username' => env('POSTMARK_WEBHOOK_USERNAME'),
            'password' => env('POSTMARK_WEBHOOK_PASSWORD'),
        ],
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
