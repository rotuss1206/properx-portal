<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | Mail goes through Azure Communication Services only (KEENEY platform rule).
    | "log" / "array" are for local development and tests.
    |
    */

    'default' => env('MAIL_MAILER', 'azure'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */

    'mailers' => [

        // symfony/azure-mailer, registered in AppServiceProvider via Mail::extend('azure').
        // Connection string format: "endpoint=https://<resource>.communication.azure.com/;accesskey=<key>".
        'azure' => [
            'transport' => 'azure',
            'connection_string' => env('KEENEY_ACS_CONNECTION_STRING'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | The verified ACS sender provided by the platform.
    |
    */

    'from' => [
        'address' => env('KEENEY_MAIL_FROM', 'no-reply@example.com'),
        'name' => env('KEENEY_MAIL_FROM_NAME', 'ProPerx Alliance'),
    ],

];
