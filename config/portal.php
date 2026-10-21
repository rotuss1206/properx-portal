<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document storage
    |--------------------------------------------------------------------------
    |
    | Member documents (license, tax, library) live only in the private Azure Blob
    | container (KEENEY platform rule; Azurite locally). No local-disk option.
    |
    */

    'documents_disk' => 'azure',

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

    /*
    |--------------------------------------------------------------------------
    | Blind index key
    |--------------------------------------------------------------------------
    |
    | HMAC key for blind indexes of encrypted, searchable fields (NPN, Member ID,
    | certificate number). Separate from APP_KEY; supplied by KEENEY via Key Vault.
    | Rotating it requires recomputing every index.
    |
    */

    'blind_index_key' => env('BLIND_INDEX_KEY'),

    'uploads' => [
        'max_kilobytes' => 10240,
        'mime_types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ],
    ],

    // Lifetime of the signed download routes (URL::temporarySignedRoute) for documents and certificates.
    'temporary_url_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Mandatory for every Nova user, members included (KEENEY platform rule,
    | Spec 8). Setting NOVA_REQUIRE_2FA=false
    | only takes effect when APP_ENV=local, for development convenience.
    |
    */

    'require_two_factor' => env('NOVA_REQUIRE_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Membership certificates (Spec 3.6)
    |--------------------------------------------------------------------------
    */

    'certificates' => [
        // The PDF contains PHI: cached only in the private Blob container, never on local disk.
        'disk' => 'azure',
        'directory' => 'certificates',
        'cache_hours' => 24,

        // Placeholder until the client provides the Executive Director's real signature. Must stay
        // inside the dompdf chroot (resources/views/pdf), or dompdf refuses to load it.
        'signature_image' => resource_path('views/pdf/images/signature-placeholder.png'),
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

    // Scheduled tasks must finish within 120 seconds (KEENEY platform rule). Jobs stop
    // cleanly once this budget is used and the next run continues where they left off.
    'scheduled_task_budget_seconds' => 100,

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
    | Session settings (timeout, cookie, driver) are owned by the KEENEY platform
    | (15 minutes, database, encrypted, secure); they are not overridden here.
    |
    */

    'security' => [
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,

        'hsts_max_age' => 31536000,

        // Content-Security-Policy; report-only mode helps when rolling out changes.
        'csp_enabled' => env('CSP_ENABLED', true),
        'csp_report_only' => env('CSP_REPORT_ONLY', false),
    ],

];
