<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document storage
    |--------------------------------------------------------------------------
    |
    | Private disk for member documents (license, tax, library). Local for now,
    | Azure Blob Storage later: only this value changes.
    |
    */

    'documents_disk' => env('PORTAL_DOCUMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Staff two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Mandatory for staff (ТЗ, розділ 8). Setting NOVA_REQUIRE_STAFF_2FA=false
    | only takes effect when APP_ENV=local, for development convenience.
    |
    */

    'require_staff_two_factor' => env('NOVA_REQUIRE_STAFF_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Membership certificates (ТЗ, розділ 3.6)
    |--------------------------------------------------------------------------
    */

    'certificates' => [
        'disk' => env('PORTAL_CERTIFICATES_DISK', env('PORTAL_DOCUMENTS_DISK', 'local')),
        'directory' => 'certificates',
        'cache_hours' => 24,

        // Placeholder until the client provides the Executive Director's real signature.
        'signature_image' => env('CERTIFICATE_SIGNATURE_IMAGE', resource_path('images/signature-placeholder.png')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public certificate verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'requests_per_minute' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled jobs
    |--------------------------------------------------------------------------
    |
    | Timezone for "night-time" schedules such as the license expiry job.
    |
    */

    'schedule_timezone' => env('PORTAL_SCHEDULE_TIMEZONE', 'America/New_York'),

];
