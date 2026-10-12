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
    | Document encryption and uploads
    |--------------------------------------------------------------------------
    |
    | License and tax documents are encrypted by the application before they are
    | written to storage (on top of Azure server-side encryption). The key falls
    | back to APP_KEY; in production supply a dedicated key from Key Vault.
    | Format: "base64:..." as produced by `php artisan key:generate --show`.
    |
    */

    'documents_encryption_key' => env('DOCUMENTS_ENCRYPTION_KEY'),

    'uploads' => [
        'max_kilobytes' => 10240,
        'mime_types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ],
    ],

    // Lifetime of SAS download links for unencrypted documents.
    'temporary_url_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Staff two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Mandatory for staff (Spec 8). Setting NOVA_REQUIRE_STAFF_2FA=false
    | only takes effect when APP_ENV=local, for development convenience.
    |
    */

    'require_staff_two_factor' => env('NOVA_REQUIRE_STAFF_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Membership certificates (Spec 3.6)
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

    /*
    |--------------------------------------------------------------------------
    | Membership payments (Spec 7)
    |--------------------------------------------------------------------------
    |
    | The portal never takes payments; it only mirrors the status sent by Stripe.
    | Past-due members keep full access for the grace period, then are treated
    | as canceled. Login is never blocked.
    |
    */

    'payments' => [
        // Where members settle their dues (e.g. the Stripe customer portal or the application site).
        'payment_url' => env('PAYMENT_PORTAL_URL'),
        'grace_days' => (int) env('PAYMENT_GRACE_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security (Spec 3.3, 8)
    |--------------------------------------------------------------------------
    |
    | Idle timeout is SESSION_LIFETIME (30 minutes); the absolute lifetime below
    | ends a session 8 hours after login regardless of activity.
    |
    */

    'security' => [
        'session_absolute_minutes' => (int) env('SESSION_ABSOLUTE_LIFETIME', 480),

        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,

        'hsts_max_age' => 31536000,

        // Content-Security-Policy; report-only mode helps when rolling out changes.
        'csp_enabled' => env('CSP_ENABLED', true),
        'csp_report_only' => env('CSP_REPORT_ONLY', false),
    ],

];
