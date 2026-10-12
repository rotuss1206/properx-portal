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

    // Transactional mail (Spec 3.4). "POSTMARK_API_TEST" is Postmark's test token:
    // requests are validated but nothing is delivered.
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),

        // Basic auth credentials configured on the Postmark bounce/spam webhook URL.
        'webhook' => [
            'username' => env('POSTMARK_WEBHOOK_USERNAME'),
            'password' => env('POSTMARK_WEBHOOK_PASSWORD'),
        ],
    ],

    // Membership payment status webhooks (Spec 7). Only the signing secret is needed:
    // the portal never calls the Stripe API or takes payments.
    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => 300,
    ],

    // API Service account (Spec 3.7, 8): the plain key comes from the environment
    // (Azure Key Vault in production); the database only stores its hash.
    // Rotate quarterly with `php artisan app:api-key:rotate`.
    'api_service' => [
        'key' => env('API_SERVICE_KEY'),
        'rotation_overlap_hours' => (int) env('API_KEY_ROTATION_OVERLAP_HOURS', 24),
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
