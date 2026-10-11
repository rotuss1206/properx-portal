# ProPerx Member Portal

Member self-service portal for ProPerx Alliance (a professional association of licensed insurance agents).
The requirements spec (Rev. 2, KEENEY) is kept outside the repository; reference it as "Spec §N".
Target hosting: Azure AKS (HIPAA tier, SHN account). Production secrets live in Azure Key Vault.

**Language:** everything in the repository is English (code, comments, tests, UI texts, emails, docs, commit messages). The client is in the US.

## Stack

- **Laravel 13**, PHP 8.4, MySQL.
- **Laravel Nova 5.11**: staff admin panel and member area (`/nova`). Installed and upgraded manually by the project owner; agents do not install it.
- **Laravel Fortify** (bundled with Nova): login, password reset, TOTP 2FA with recovery codes.
- **spatie/laravel-permission**: roles and permissions (`HasRoles` trait on `App\Models\User`).
- **barryvdh/laravel-dompdf**: membership certificate PDF.
- **Postmark**: transactional email (`MAIL_MAILER=log` locally).
- **Azure Blob Storage** (`azure-oss/storage-blob-laravel`, disk `azure`): file storage for documents and the certificate cache. `PORTAL_DOCUMENTS_DISK` / `PORTAL_CERTIFICATES_DISK` switch between `local` and `azure`. Locally the Azurite emulator stands in for Azure. Always go through `Storage::disk(...)`.

## Roles (Spec §3.7)

| Role | Key permissions |
|---|---|
| Executive Director | Full access. The only role that creates and changes staff accounts. Certificate signatory. Cannot be revoked through the UI. |
| Membership Coordinator | Member CRUD, license document review, member communications. No library, tax documents or staff management. |
| Compliance Specialist | Library CRUD, tax document uploads linked to members, read-only access to members. |
| Member | Own profile, documents, certificate and settings only; read-only library. |
| API service account | Not a person, no web login. Authenticates with an API key (rotated quarterly); least privilege per task (Stripe webhooks, scheduled jobs). |

Auditor/Board Member and Treasurer roles are out of scope for the initial build.

## Main features (Spec §2)

- Email and password login, password reset, optional TOTP MFA with recovery codes (mandatory for staff).
- Member profile: contact details, business, NPN, license (number, state, type, expiry date).
- License documents: PDF/JPG/PNG up to 10 MB, private encrypted storage, served only through a server endpoint that checks role and ownership.
- Daily job for license statuses (active / expiring / expired) and reminders 60, 30 and 7 days ahead, honouring notification preferences. Every sent or skipped email is written to the reminder log.
- Membership certificate PDF with a verification number. Cached for 24 hours, regenerated only when its data changes.
- Tax document vault, compliance and training library (categories, search), notification preferences per category.
- Audit log: login, logout, password, MFA, profile, document upload/download/deletion, role changes; with IP and user agent.

## Out of scope

SSO with external identity providers, member-to-member messaging, events and calendars, CE credit tracking, third-party license verification APIs, native mobile apps.

## Development rules

- **Migrations with indexes**: on every foreign key (`foreignId()->constrained()`) and on columns used for filtering or sorting (statuses, expiry dates, `user_id + created_at` in logs).
- **Eloquent relationships** (`hasOne`, `hasMany`, `belongsTo`, ...) instead of manual joins and raw queries. Avoid N+1 with `with()`.
- **No hard-coded secrets**: keys, passwords, tokens and service URLs come only from `.env` via `config/*.php`. Use `config()` in code, never `env()`. Add new variables to `.env.example` with an empty value.
- Authorization through Policies and spatie permissions. File access is checked server-side on every request; there are no public URLs.
- Sensitive fields are stored encrypted. MFA secret and recovery codes: Fortify's `two_factor_*` columns (Fortify encrypts recovery codes rather than hashing them as Spec §3.3 asks; known deviation).

## Access and Nova

- The `viewNova` gate ([NovaServiceProvider](app/Providers/NovaServiceProvider.php)) applies in every environment, including local. Four roles get into Nova; the API Service account does not.
- Permissions are defined in [RolesAndPermissionsSeeder](database/seeders/RolesAndPermissionsSeeder.php). Policies in `app/Policies` check spatie permissions only (`checkPermissionTo`), never role names.
- The Nova menu is built automatically from the policies' `viewAny`; no per-role menu is needed.
- Members see only their own records: `indexQuery` via `Resource::ownRecordsUnless()` plus `view` in the policy.
- The `Staff` and `Member` resources both use the `User` model; their `viewAny` and `create` checks are split in the `AuthorizesUserResource` trait.
- [RequireTwoFactorForStaff](app/Http/Middleware/RequireTwoFactorForStaff.php) middleware: staff without confirmed 2FA can only reach `/nova/user-security`. 2FA is optional for members. `NOVA_REQUIRE_STAFF_2FA=false` disables the requirement, but only when `APP_ENV=local`.
- Files live on the private disk `config('portal.documents_disk')`. All writes go through [DocumentVault](app/Services/Documents/DocumentVault.php):
  - UUID file names; the extension comes from the MIME type sniffed with `finfo`, never from the client.
  - Uploads are limited to PDF/JPG/PNG up to 10 MB (`DocumentVault::uploadRules()`).
  - License and tax documents are AES-encrypted by the app (`.enc` suffix, key `DOCUMENTS_ENCRYPTION_KEY`, falling back to `APP_KEY`).
- Downloads go only through Nova's download endpoint after a policy check (`App\Services\DocumentDownloader`): encrypted files are decrypted and streamed, others get a 5-minute SAS link (or a signed local URL). Every download is audited.
- Security (Spec §3.3, §8):
  - Login: max 5 failed attempts per 15 minutes per IP (`App\Auth\LoginRateLimiter` replaces Fortify's). The 2FA challenge allows 5 tries per minute.
  - Session: 30 minutes idle (`SESSION_LIFETIME`), 8 hours absolute (`EnforceAbsoluteSessionTimeout`); "remember me" is ignored (`DisableRememberMe`). Cookies are `Secure`, `HttpOnly`, `SameSite=Strict`.
  - `SecurityHeaders` sends HSTS (HTTPS or production), `nosniff`, `X-Frame-Options: DENY` and a CSP. Nova's inline scripts get the per-request nonce via `Nova::cspNonce()`; no `unsafe-eval`. `CSP_REPORT_ONLY=true` switches to report-only while debugging.
- Audit log: `AuditLog::record($action, $resource)`. Login and logout are recorded by listeners in `app/Listeners`, which also update `last_login_at`.
- Seeded test accounts (password `password`): `director@`, `coordinator@`, `compliance@`, `member01@` … `member30@properx.test`. The seeder writes placeholder files for documents (`DemoFilesSeeder`).
- Tests: the base `Tests\TestCase` calls `Nova::flushState()` because Nova caches request data in static properties.

## Certificates and reminders

- [CertificateGeneratorService](app/Services/Certificates/CertificateGeneratorService.php): PDF from `resources/views/pdf/certificate.blade.php`.
  - Cached for 24 h on the `portal.certificates.disk` disk; the file name is a hash of the data printed on the certificate.
  - `MemberProfileObserver` clears the cache when that data changes.
  - Throws `CertificateUnavailableException` (with a user-safe message) for expired licenses or incomplete profiles.
  - Bump `TEMPLATE_VERSION` after changing the template.
- Certificates are downloaded only through the `certificates.download` route (policy `downloadCertificate`). Public check: `/verify-certificate/{code}`, limited to 10 requests per minute per IP; shows name and dates only.
- "Valid from" = member account creation date, "Valid through" = `license_expires_at` (the data model has no separate membership dates).
- `app:check-license-expirations` runs daily at 02:00 in `PORTAL_SCHEDULE_TIMEZONE`.
  - Refreshes license statuses and sends 60/30/7-day reminders.
  - Deduplicates via `reminder_logs` (`user_id + reminder_type + license_expires_at`).
  - Members who opted out get no email; a `skipped` entry is logged instead.
  - Alerts the Executive Director on errors.
- Mail: `MAIL_MAILER=postmark` with `POSTMARK_TOKEN` (`POSTMARK_API_TEST` = Postmark's test token, nothing is delivered) or `log`.
  - Reminder emails carry the Postmark tag `license-expiry-reminder` and `reminder_log_id` metadata.
  - `POST /webhooks/postmark` (basic auth `POSTMARK_WEBHOOK_USERNAME/PASSWORD`, stateless routes in `routes/webhooks.php`) maps Delivery / Bounce / SpamComplaint to `reminder_logs.delivery_status`; never downgrades a worse status.

## Local environment (Windows, OSPanel 6)

`php`, `composer` and `git` are not on PATH, so call them by full path:

- PHP: `D:\WORK\OSPanel6\modules\PHP-8.4\php.exe`
- Composer: `<php> D:\WORK\OSPanel6\data\PHP-8.4\default\composer\composer.phar`
- Git (bundled with GitHub Desktop; the path changes on updates): `C:\Users\PC\AppData\Local\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`
- Site: `https://properx-portal.local` (Apache + PHP-8.4, document root `public`).
- Repository: `https://github.com/rotuss1206/properx-portal`, branch `main`.
- Tests: `<php> vendor\bin\phpunit`.
- Azure emulator: `npm install` once, then `npm run azurite` (data in `storage/azurite`) and `<php> artisan app:storage:init` to create the container. The local `.env` uses `PORTAL_DOCUMENTS_DISK=azure`, so Azurite must be running for document downloads. Tests use the local disk; `AzuriteIntegrationTest` runs only when Azurite is up.
- Nova assets (`public/vendor/nova`) are not committed; Composer publishes them (`nova:publish`) after install/update.
- The queue (`QUEUE_CONNECTION=database`) is not processed automatically locally: `<php> artisan queue:work`. Scheduler: `<php> artisan schedule:work`.
- PowerShell 5.1 reads files as ANSI: never edit files containing non-ASCII text via `Get-Content`/`Set-Content`; use the Edit tool.
