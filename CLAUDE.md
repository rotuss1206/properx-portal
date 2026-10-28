# ProPerx Member Portal

Member self-service portal for ProPerx Alliance (a professional association of licensed insurance agents).
The requirements spec (Rev. 2, KEENEY) is kept outside the repository (local `docs/*.docx` is git-excluded); reference it as "Spec N".
Target hosting: Azure AKS (HIPAA tier, SHN account). Production secrets live in Azure Key Vault.

**Language:** everything in the repository is English (code, comments, tests, UI texts, emails, docs, commit messages). The client is in the US.

## Stack

- **Laravel 13**, PHP 8.4, MySQL.
- **Laravel Nova 5.11**: staff admin panel and member area (`/nova`). Installed and upgraded manually by the project owner; agents do not install it.
- **Laravel Fortify** (bundled with Nova): login, password reset, TOTP 2FA with recovery codes.
- **Livewire 4** (public forms only, `/join/agency`): `csp_safe` mode, because Livewire 3 compiles `wire:` expressions with `new Function` and the CSP has no `unsafe-eval`. Assets come from Livewire's route (`@livewireScripts` with the Vite nonce, `inject_assets` off); no Vite build needed.
- **spatie/laravel-permission**: roles and permissions (`HasRoles` trait on `App\Models\User`).
- **barryvdh/laravel-dompdf**: membership certificate PDF.
- **Azure Communication Services** (`symfony/azure-mailer`, `Mail::extend('azure')`): transactional email; the only mail provider allowed by the KEENEY platform (`MAIL_MAILER=log` locally).
- **Azure Blob Storage** (`azure-oss/storage-blob-laravel`, disk `azure`): the only storage for member documents and the certificate PDF cache (no local-disk option). In Azure: managed identity with `KEENEY_DOCUMENTS_ACCOUNT` / `_CONTAINER` / `_ENDPOINT` (the account has no key). Locally: the Azurite emulator via `AZURITE_CONNECTION_STRING`. Always go through `Storage::disk('azure')`.

## Roles (Spec 3.7)

| Role | Key permissions |
|---|---|
| Executive Director | Full access. The only role that creates and changes staff accounts. Certificate signatory. Cannot be revoked through the UI. |
| Membership Coordinator | Member CRUD, license document review, member communications. No library, tax documents or staff management. |
| Compliance Specialist | Library CRUD, tax document uploads linked to members, read-only access to members. |
| Member | Own profile, documents, certificate and settings only; read-only library. |
| Agency Principal | Layered on Member: sees and manages only the agency they lead (`own-agency.*`, checked in `AgencyPolicy`). Assigned automatically from `agencies.principal_user_id`; reassigned by staff only. |
| API service account | Not a person, no web login. Authenticates with an API key (rotated quarterly); least privilege per task (Stripe webhooks, scheduled jobs). |

Auditor/Board Member and Treasurer roles are out of scope for the initial build.

## Main features (Spec 2)

- Email and password login, password reset, TOTP MFA with recovery codes (mandatory for every Nova user on the KEENEY platform).
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
- **Encrypted casts** (KEENEY rule): `member_profiles.member_number` (Member ID), `npn`, `certificate_code`, `phone`, `address`; agency `agency_npn`, `fein`, `legal_address`, `mailing_address`, `phone`, `billing_email`; license document `original_filename`, `mime_type`, `admin_notes`; tax document `document_type`, `tax_year`, `original_filename`. Encrypted columns cannot be sorted, filtered or LIKE-searched in SQL.
  - Lookups use **blind indexes** (`*_index` columns, HMAC-SHA256 keyed by `BLIND_INDEX_KEY`, separate from `APP_KEY`): trait `HasBlindIndexes` + `Model::query()->whereBlindIndex('npn', $value)` (exact match, case/space-insensitive). The Nova member search uses them for NPN, Member ID and certificate number.
- Logs and email bodies carry no PHI: IDs only, never emails, names or NPNs. Exception messages may hold member data, so log `$e::class`; `QueryException` is reported without bindings (`bootstrap/app.php`).
- Sensitive fields are stored encrypted. MFA secret and recovery codes: Fortify's `two_factor_*` columns (Fortify encrypts recovery codes rather than hashing them as Spec 3.3 asks; known deviation).

## Access and Nova

- Branding: name "ProPerx Alliance", placeholder text logo `resources/images/logo.svg`, navy primary palette in `config/nova.php` (`brand`), footer `resources/views/nova/footer.blade.php`. Replace the logo and signature placeholders when the client provides artwork.
- The `viewNova` gate ([NovaServiceProvider](app/Providers/NovaServiceProvider.php)) applies in every environment, including local. Four roles get into Nova; the API Service account does not.
- Permissions are defined in [RolesAndPermissionsSeeder](database/seeders/RolesAndPermissionsSeeder.php). Policies in `app/Policies` check spatie permissions only (`checkPermissionTo`), never role names.
- The Nova menu is built automatically from the policies' `viewAny`; no per-role menu is needed.
- Members see only their own records: `indexQuery` via `Resource::ownRecordsUnless()` plus `view` in the policy.
- The `Staff` and `Member` resources both use the `User` model; their `viewAny` and `create` checks are split in the `AuthorizesUserResource` trait.
- [RequireTwoFactor](app/Http/Middleware/RequireTwoFactor.php) middleware: 2FA is mandatory for every Nova user, members included (KEENEY rule); without confirmed 2FA only `/nova/user-security` is reachable. `NOVA_REQUIRE_2FA=false` disables the requirement, but only when `APP_ENV=local`.
- Files live on the private disk `config('portal.documents_disk')`. All writes go through [DocumentVault](app/Services/Documents/DocumentVault.php):
  - UUID file names; the extension comes from the MIME type sniffed with `finfo`, never from the client.
  - Uploads are limited to PDF/JPG/PNG up to 10 MB (`DocumentVault::uploadRules()`).
  - License and tax documents are AES-encrypted by the app (`.enc` suffix, key `DOCUMENTS_ENCRYPTION_KEY`, falling back to `APP_KEY`).
- Downloads (`App\Services\DocumentDownloader`): never a public or SAS URL. After Nova's policy check the download endpoint redirects to a 5-minute `URL::temporarySignedRoute` (`documents.download`, `certificates.download`); that route requires the session, re-checks the policy and streams the blob (decrypting `.enc` files). Every download is audited.
- Security (Spec 3.3, 8):
  - Login: max 5 failed attempts per 15 minutes per IP (`App\Auth\LoginRateLimiter` replaces Fortify's). The 2FA challenge allows 5 tries per minute.
  - Session: owned by the KEENEY platform (`SESSION_*`: 15 minutes, database, encrypted, secure, `lax`); `config/session.php` stays stock, do not add custom timeouts or cookie names. "Remember me" is ignored (`DisableRememberMe`).
  - `SecurityHeaders` sends HSTS (HTTPS or production), `nosniff`, `X-Frame-Options: DENY` and a CSP. Nova's inline scripts get the per-request nonce via `Nova::cspNonce()`; no `unsafe-eval`. `CSP_REPORT_ONLY=true` switches to report-only while debugging.
- Audit log: `AuditLog::record($action, $resource)` (actor = current user). Sources:
  - listeners in `app/Listeners`: login/logout (also updates `last_login_at`), role changes via spatie events (`permission.events_enabled`);
  - `UserObserver`: password changes, 2FA enrolment/removal;
  - `MemberProfileObserver`: profile edits (not the nightly status refresh);
  - `DocumentObserver`: uploads and deletions of license, tax and library documents;
  - explicit calls: document review actions, document and certificate downloads, certificate generation.
- Reference data (roles, permissions, the API Service account) lives in `App\Services\ReferenceData` (additive, idempotent, never deletes) and ships as migrations, since the KEENEY pipeline runs only `migrate --force`: `seed_reference_data` calls it, and so do `DatabaseSeeder` (`RolesAndPermissionsSeeder`, `ServiceAccountSeeder`). New roles or permissions: add them to `ReferenceData` AND add a new migration calling `seed()`; never edit an old one. No factories or Faker in it (dev-only packages, absent from the image).
- Fictional data comes from `DemoSeeder` (`php artisan migrate:fresh --seeder=DemoSeeder`), which refuses to run unless `DEMO_SEED_ALLOWED=true`. Fixed data in `Database\Seeders\Support\DemoPeople` and the seeders, no Faker or factories (checked by `DemoSeederTest`), idempotent (an existing record is kept). The `@properx.test` accounts (`director@`, `coordinator@`, `compliance@`, `member01@` … `member30@`, agency principals and people below) get the password `password` only with `APP_ENV=local` (`DemoPeople::testPassword()`), random unknown passwords elsewhere. Placeholder files for documents: `DemoFilesSeeder` (only for documents whose file is missing). With `DEMO_LOGIN_EMAIL` set, `DemoLoginSeeder` adds one login per role (director, coordinator, compliance, member, agency principal) on its plus addresses with random passwords (also locally) that are NEVER printed: seeder output can reach platform logs, so it lists the emails only. Sign in via "Forgot your password?" or `php artisan app:password-link {email}` (prints the one-time Nova reset link; audited as `password_link_created`, never logged). It is guarded by `DEMO_SEED_ALLOWED` too.
- Database TLS: `config/database.php` adds `Pdo\Mysql::ATTR_SSL_CA` + `ATTR_SSL_VERIFY_SERVER_CERT` only when `MYSQL_ATTR_SSL_CA` is set (the platform's CA); locally no TLS.
- Tests: the base `Tests\TestCase` calls `Nova::flushState()` because Nova caches request data in static properties.

## Certificates and reminders

- [CertificateGeneratorService](app/Services/Certificates/CertificateGeneratorService.php): PDF from `resources/views/pdf/certificate.blade.php`.
  - Cached for 24 h on the `portal.certificates.disk` disk; the file name is a hash of the data printed on the certificate.
  - `MemberProfileObserver` clears the cache when that data changes.
  - Throws `CertificateUnavailableException` (with a user-safe message) for expired licenses or incomplete profiles.
  - Bump `TEMPLATE_VERSION` after changing the template.
  - `config/dompdf.php` is hardened: `enable_remote`, `enable_php`, `enable_javascript` off, only `data://` and `file://`, chroot `resources/views/pdf`. Fonts (`pdf/fonts`) and the signature image (`pdf/images/signature-placeholder.png`, replace with the real one) must live inside the chroot; QR codes are data URIs; the font cache is `storage/framework/cache/dompdf-fonts` (the only writable storage paths in the container are `storage/app`, `storage/framework/*`, `storage/logs`, `bootstrap/cache`; the service creates the directory before rendering).
- Certificates are downloaded only through the `certificates.download` route (policy `downloadCertificate`). Public check: `/verify-certificate/{code}`, limited to 10 requests per minute per IP; shows name and dates only.
- "Valid from" = member account creation date, "Valid through" = `license_expires_at` (the data model has no separate membership dates).
- `app:check-license-expirations` runs hourly 02:00–05:00 in `PORTAL_SCHEDULE_TIMEZONE`, `onOneServer()->withoutOverlapping(5)`.
  - Platform limit: scheduled tasks finish within 120 s. The command stops at `portal.scheduled_task_budget_seconds` (100) and the next run continues; every run must stay idempotent.
  - Refreshes license statuses and sends 60/30/7-day reminders.
  - Deduplicates via `reminder_logs` (`user_id + reminder_type + license_expires_at`).
  - Members who opted out get no email; a `skipped` entry is logged instead.
  - Alerts the Executive Director on errors.
- Mail: default mailer `azure` (`App\Mail\AcsTransportFactory` parses `KEENEY_ACS_CONNECTION_STRING`; sender `KEENEY_MAIL_FROM` / `KEENEY_MAIL_FROM_NAME`). Use `log` locally.
  - Email bodies contain no PHI (no names, license numbers, dates): they only link back to the portal.
  - Branding: published templates in `resources/views/vendor/mail` (header, message, layout, theme) and `vendor/notifications/email.blade.php` use `config('portal.brand')` ("ProPerx Alliance", Nova colours), never `app.name`; the header is a text logo (no remote images, Outlook blocks them). Only the customised templates are published. `MailBrandingTest` renders the reset, reminder and task-failure emails with `app.name = Laravel` and checks no framework branding or PHI.

## Agency membership (spec "ProPerx Agency Membership", Option B)

- Requirements: [docs/specs/agency-membership.md](docs/specs/agency-membership.md) (English summary of the client spec; cite as "Agency spec §N"). We build **Option B** (Agency spec §12). The spec lives in this repository only: `sync-to-keeney.ps1` copies no `docs/` file except `docs/PORTAL.md`.
- Agencies enroll 2+ people on one annual invoice. `agencies` (identifiers and contact details encrypted; `agency_npn`, `fein` with blind indexes), `partner_codes` (MGA/FMO; `PartnerCode::findValid()`).
- Every person is a user (Member role) with a `member_profiles` row: `agency_id`, `agency_role` (principal/member), `person_type` (licensed producer/agency employee, employees have no NPN), `invite_status`, `status`. `member_type` follows `agency_id` (set in `MemberProfile::syncMembershipType()`).
- `headcount` follows the roster automatically (`Agency::syncHeadcount()`, saved quietly). The tier is not changed automatically: dropping below a tier changes the rate only at renewal.
- The principal: `agencies.principal_user_id` (unique). `AgencyObserver` calls `Agency::syncPrincipal()`, which puts the principal on the roster and moves the Agency Principal role; a principal cannot be moved out of the agency before reassignment (`LogicException`).
- Pricing: `App\Services\Agencies\AgencyPricingService` (no Stripe calls) returns an `AgencyQuote` in cents. Tier by headcount (minimum 2, `HeadcountTooLowException`), founding rates for payments before 2027-01-01 00:00 Eastern (locked at renewal via `rateType` / `quoteForAgency()`), a valid partner code gives min($149, tier rate), 2.9% surcharge only for `card.funding = credit`. Rates, the cutoff and Stripe prices (lookup keys, optional `STRIPE_PRICE_AGENCY_*` IDs) live in `config/agency.php`.
- Nova (group "Agencies"): `Agency` (tier, rate type, rate, headcount, status; filters by type, tier, rate type and status; People = roster; principal reassignment) and `PartnerCode`. Staff-only: both resources override `authorizedTo()` to require `agencies.view`, so a principal's own-agency rights in `AgencyPolicy` do not open Nova screens. Staff move people between agencies on the member profile ("Agency Membership" panel); members see those fields read-only. Encrypted uniqueness: `App\Rules\UniqueBlindIndex`; per-field index normalisation via `normalizeForBlindIndex()` (FEIN/NPN digits only).
- Seeders run with model events muted: `AgencySeeder` calls `syncPrincipal()` / `syncHeadcount()` itself. Accounts: `principal01@` … `principal03@properx.test` (5, 12 and 55 people), people `agency01.person02@properx.test` etc.
- Audited: `agency_created/updated/deleted`, `agency_principal_changed`, `agency_member_added/removed`, `partner_code_created/updated/deleted`.
- Enrollment wizard (public, `/join/agency`, `App\Livewire\AgencyRegistration\Wizard`, layout `join/layout.blade.php`): six screens, 1–4 done (Membership type, Tier, Agency, Principal), 5–6 placeholders. One `StepForm` per screen (`rules()`, `normalize()`, `toDraft()`/`fillFromDraft()`); "Continue" validates the current screen, "Back" and the progress indicator save without validating; `step`/`furthestStep` are `#[Locked]`.
  - Draft: `agency_registration_drafts` (`data` `encrypted:array`, `token_hash` for a future continue link, `expires_at` 30 days after the last save, pruned daily by `model:prune`); the session holds only the draft id (`Wizard::SESSION_KEY`). Created on the first "Continue", not on page load.
  - Query parameters: `?partner=CODE` fills the partner code; `?tier=agency_10to49` or `?tier=agency-plus` preselects the tier and opens screen 2.
  - Price block: `AgencyPricingService::quote()` for the chosen tier's minimum headcount until the People screen exists.
  - Duplicates: principal email against `users.email`, NPN, agency NPN and FEIN through blind indexes ("Please contact ProPerx"). Legal address: `App\Rules\NotPoBox`; phones: `App\Rules\UsPhone`.
  - Rate limits per IP: `agency-registration` (page) and `livewire` (Livewire's update route, replaced in `AppServiceProvider` with the same path). Nothing is logged.

## Stripe payment status (Spec 7)

- The portal takes no payments. `POST /api/webhooks/stripe` is authenticated by the Stripe signature only (`STRIPE_WEBHOOK_SECRET`, `stripe/stripe-php`; no API key) and runs as the API Service account (`ServiceAccount::user()`) through `App\Services\Stripe\StripeWebhookHandler`.
- Idempotency: `stripe_events` (event id primary key). Out-of-order events: ignored when older than `member_profiles.payment_event_at`.
- `payment_status_updated_at` = when the status last changed; it starts the `PAYMENT_GRACE_DAYS` grace period for `past_due`.
- `MemberProfile::hasRestrictedMembership()` (canceled, or past due beyond grace) closes certificate, library and license documents for the member; profile, notification settings and tax documents stay open. Login is never blocked.
- Banner: `App\Support\MembershipBanner` + `resources/js/nova/membership-banner.js` (Nova script, fixed to the bottom; no innerHTML, CSP-safe).
- Local testing with the Stripe CLI and the test accounts: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Machine API (Spec 3.7, 8)

- Health probes (`routes/health.php`, no auth, no session, no throttle): `GET /api/health/live` (liveness, no dependencies) and `GET /api/health` (readiness: database, cache, queue, documents disk; 503 on failure). Responses show only `ok`/`fail`; reasons go to the log.
- API Service account: a system user with the API Service role, created by `ServiceAccountSeeder` (part of `DatabaseSeeder`, i.e. reference data). `App\Services\ServiceAccount::user()` finds it by role, without a key; it is the actor of automated work such as the Stripe webhook (503 if it is missing or lacks the webhook permissions).
- API Service key: only for external callers (`/api/service/*`, future `/api/intake/*`); the Stripe webhook does not use it. `api_keys` stores a SHA-256 hash only; the plain key comes from `API_SERVICE_KEY` (Key Vault). `App\Services\ApiKeys\ApiKeyManager`:
  - middleware `auth.api-key[:permission]` for automated callers (`Authorization: Bearer` or `X-Api-Key`), e.g. `GET /api/service/whoami`.
  - `php artisan app:api-key:rotate [--overlap=24]`: quarterly rotation; prints the new key once, the old key expires after the overlap window; audited as `api_key_rotated`.
- Other stateless endpoints (webhooks, service API) live in `routes/machine.php`, rate limited 120/min per IP.

## Local environment (Windows, OSPanel 6)

`php`, `composer` and `git` are not on PATH, so call them by full path:

- PHP: `D:\WORK\OSPanel6\modules\PHP-8.4\php.exe`
- Composer: `<php> D:\WORK\OSPanel6\data\PHP-8.4\default\composer\composer.phar`
- Git (bundled with GitHub Desktop; the path changes on updates): `C:\Users\PC\AppData\Local\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`
- Site: `https://properx-portal.local` (Apache + PHP-8.4, document root `public`).
- Repository: `https://github.com/rotuss1206/properx-portal`, branch `main`.
- KEENEY repository (Azure DevOps `keeneycs-shn-portal`, clone `D:\WORK\Avedsoft\Kevin\Portal\keeneycs-shn-portal`, branch `feature/portal-app`): filled only by `scripts/sync-to-keeney.ps1` (mirror of the application paths plus `docs/PORTAL.md`; never commits). Keep `docs/PORTAL.md` as the portal description there; the clone's README.md belongs to the platform.
- Tests: `<php> vendor\bin\phpunit`.
- `package-lock.json` must be generated with the image's toolchain, Node 22 / npm 10 (`node:22.20.0-trixie-slim`): the KEENEY build runs `npm ci`, and a lock written by npm 11 left out `react` (needed by `@laravel/multiplex`, the skeleton's optional dependency for `php artisan dev` on macOS/Linux). Without Docker use a portable Node 22.20.0 (`npm install --package-lock-only`, then `npm ci`).
- Azure emulator: `npm install -g azurite` once (not a project dependency), then `npm run azurite` (data in `storage/azurite`) and `<php> artisan app:storage:init` to create the container. Azurite must be running for document and certificate downloads. Tests fake the `azure` disk (`Tests\TestCase`); `AzuriteIntegrationTest` runs only when Azurite is up.
- Nova assets (`public/vendor/nova`) are not committed; Composer publishes them (`nova:publish`) after install/update.
- The queue (`QUEUE_CONNECTION=database`) is not processed automatically locally: `<php> artisan queue:work`. Scheduler: `<php> artisan schedule:work`.
- PowerShell 5.1 reads files as ANSI: never edit files containing non-ASCII text via `Get-Content`/`Set-Content`; use the Edit tool.
