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

    // When document storage (Azure Blob; Azurite locally) does not answer, downloads fail fast with
    // "temporarily unavailable" instead of hanging: a short reachability probe runs first
    // (App\Services\Documents\StorageHealth; its outcome is cached for a few seconds). The Blob
    // client's own timeouts are on the azure disk in config/filesystems.php.
    'storage' => [
        'timeout' => (int) (env('DOCUMENTS_STORAGE_TIMEOUT') ?: 20),
        'connect_timeout' => (int) (env('DOCUMENTS_STORAGE_CONNECT_TIMEOUT') ?: 5),
        'probe_timeout' => 3,
        'probe_retries' => 1,
        'probe_cache_seconds' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Brand (emails)
    |--------------------------------------------------------------------------
    |
    | Header, signature and footer of every email (resources/views/vendor/mail and
    | vendor/notifications). Fixed rather than APP_NAME, so mail never shows framework
    | branding when a server leaves APP_NAME unset. Colours match the Nova palette
    | (config/nova.php "brand": 800 navy, 600 for buttons).
    |
    */

    'brand' => [
        'name' => 'ProPerx Alliance',
        'tagline' => 'Member Portal',
        'navy' => '#1e3a5f',
        'button' => '#21579d',
        // "Contact support" on the sign-in page (https:// or mailto:); without it the page
        // names the membership team instead of linking.
        'support_url' => env('PORTAL_SUPPORT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova sidebar (App\Nova\Menu\SidebarMenu)
    |--------------------------------------------------------------------------
    |
    | Billing has no page yet: the member menu shows it only once this Nova path
    | (e.g. "/billing") or URL is set.
    |
    */

    'menu' => [
        'billing_path' => env('PORTAL_BILLING_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Member area (/member) — docs/specs/member-area.md
    |--------------------------------------------------------------------------
    |
    | The Blade + Livewire area that replaces Nova for the Member role. Off until it is
    | finished; members keep using Nova meanwhile. Each default below is one line to change.
    |
    */

    'member_area' => [
        'enabled' => (bool) env('MEMBER_AREA_ENABLED', false),

        // Header search: own documents (license, tax, certificate) and the library (App\Member\MemberSearch).
        'header_search' => true,

        // "Founding member" badge for individual members: no data source yet (agency people
        // of a founding-rate agency show it).
        'founding_badge_individuals' => false,

        // Not portal data (CE tracking is out of scope; benefit enrollment is not recorded).
        'ce_credits' => false,
        'benefit_statuses' => false,

        // Benefits tab (dashboard): one card per benefit, in this order; an empty list hides the
        // tab. 'icon' is a symbol of public/portal/img/icons.svg; 'url' (optional) adds a
        // "Learn more" link. Every card says "Available" (enrollment is not recorded, see
        // benefit_statuses). Texts from the mockup until the client provides the catalogue.
        'benefits' => [
            ['title' => 'Group health insurance', 'description' => 'Nationwide PPO group health plan — effective January 1, 2027.', 'icon' => 'benefit-health', 'url' => null],
            ['title' => 'Group dental', 'description' => 'Annual coverage from $2,000 to $5,000 at group rates.', 'icon' => 'benefit-dental', 'url' => null],
            ['title' => 'Group vision', 'description' => 'Comprehensive vision benefit at negotiated group rates.', 'icon' => 'benefit-vision', 'url' => null],
            ['title' => 'Group pet insurance', 'description' => 'Save up to 40% on pet insurance for your whole family.', 'icon' => 'benefit-pet', 'url' => null],
            ['title' => 'CRM for agents', 'description' => 'A purpose-built CRM with a member discount, included.', 'icon' => 'benefit-crm', 'url' => null],
            ['title' => 'Business discounts', 'description' => 'VOIP, payroll, office supply and print savings.', 'icon' => 'benefit-discounts', 'url' => null],
        ],

        // "Scanned for malware" wording only once uploads are scanned.
        'malware_scan' => false,

        // The license upload asks only for the document type (no license number or dates).
        'license_document_types' => [
            'resident' => 'Resident license',
            'non_resident' => 'Non-resident license',
            'other' => 'Other',
        ],

        'agency_people' => [
            // Agency people hold NPN + resident state only; no license document uploads.
            'license_upload' => false,
            // The principal sees the agency's subscription; other agency people see no Billing.
            'principal_billing' => true,
        ],

        'certificate' => [
            // "Membership valid through": paid_through ("paid_through_at", yearly from the
            // payment date) or license_expiry (today's rule). Falls back to the license
            // expiry while there is no Stripe date.
            'valid_through' => 'paid_through',
            // First signature: name null = the longest-serving Executive Director, as today.
            'signatory' => ['name' => null, 'title' => 'Executive Director'],
            // Optional second signature, e.g. ['name' => 'R. Alvarez', 'title' => 'Membership Secretary'].
            'second_signatory' => null,
        ],
    ],

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
    | Agency enrollment (/join/agency)
    |--------------------------------------------------------------------------
    |
    | Public six-step wizard. "Just me" sends visitors to the individual application
    | on the marketing site (a Gravity Form run by KEENEY, outside the portal). The
    | draft (encrypted) is kept for draft_days after its last save.
    |
    */

    'registration' => [
        // Off (404) unless enabled: screens 5-6 are still placeholders.
        'enabled' => (bool) env('AGENCY_ENROLLMENT_ENABLED', false),
        'individual_url' => env('INDIVIDUAL_MEMBERSHIP_URL'),
        'draft_days' => 30,
        // Per IP: page loads, and Livewire round trips (every "Continue", "Back", blurred field).
        'page_requests_per_minute' => 30,
        'livewire_requests_per_minute' => 120,
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

    // Database\Seeders\DemoSeeder (fictional people and documents) runs only when this is true.
    // Local development only; never set on the platform.
    'demo_seed_allowed' => (bool) env('DEMO_SEED_ALLOWED', false),

    // Optional: a real inbox for demo logins. DemoSeeder then creates one account per role on its
    // plus addresses (name+director@, +coordinator@, +compliance@, +member@, +agency@) with random
    // passwords that are never shown: sign in via "Forgot your password?" or app:password-link.
    'demo_login_email' => env('DEMO_LOGIN_EMAIL'),

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
