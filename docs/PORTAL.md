# ProPerx Member Portal

Member self-service portal for ProPerx Alliance, a professional association of licensed
insurance agents. Members manage their profile, license data, documents, membership
certificate and notification preferences; staff run the association from the same Nova
panel; agencies enroll several people on one annual invoice.

The platform contract for this repository (pipelines, image, hygiene gate, settings) is in
the root `README.md`, owned by KEENEY. This file describes the application.

## Stack

| Area | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Admin and member area | Laravel Nova 5 (`/nova`), Fortify TOTP two-factor, mandatory for every Nova user |
| Roles | spatie/laravel-permission: Executive Director, Membership Coordinator, Compliance Specialist, Member, Agency Principal, API Service |
| Database | MySQL 8 (TLS with a verified certificate when `MYSQL_ATTR_SSL_CA` is set) |
| Files | Private Azure Blob container (`azure-oss/storage-blob-laravel`), managed identity; Azurite locally |
| Mail | Azure Communication Services (`symfony/azure-mailer`); no PHI in bodies |
| PDF | barryvdh/laravel-dompdf, hardened (`config/dompdf.php`) |
| Payments | Stripe webhooks only (`stripe/stripe-php`); the portal takes no payments |

Main features: member profiles with encrypted identifiers (blind indexes for lookups),
license and tax documents (encrypted, streamed through short-lived signed routes after a
policy check), a compliance library, membership certificates with public verification,
license expiry reminders, agency membership (agencies, partner codes, pricing), Stripe
payment status, health probes and an audit log.

## Local setup

Requirements: PHP 8.4 with `pdo_mysql`, `gd`, `intl`, `zip`, `bcmath`; Composer 2; Node 22;
MySQL 8. Nova is installed from `nova.laravel.com`, so Composer needs your own Nova
credentials locally (`composer config http-basic.nova.laravel.com <email> <license-key>`,
stored in your user Composer config, never in the repository).

```bash
composer install
npm install
npm install -g azurite                           # Blob emulator, once (not a project dependency)
cp .env.example .env
php artisan key:generate                         # local .env only; never on the platform
npm run azurite                                  # starts the emulator, in its own terminal
php artisan app:storage:init                     # creates the local container
php artisan migrate:fresh --seeder=DemoSeeder    # fictional data, needs DEMO_SEED_ALLOWED=true
npm run build
```

Set in `.env` at least: `DB_*`, `AZURITE_CONNECTION_STRING` (the value in `.env.example`),
`BLIND_INDEX_KEY` (`php -r "echo 'base64:'.base64_encode(random_bytes(32));"`),
`MAIL_MAILER=log` and `DEMO_SEED_ALLOWED=true`.

Demo accounts (password `password`; two-factor setup is asked on first login):
`director@`, `coordinator@`, `compliance@`, `member01@` … `member30@properx.test`,
agency principals `principal01@` … `principal03@properx.test`.

Logins on a real inbox: set `DEMO_LOGIN_EMAIL=you@example.com` before seeding, and the seeder
adds one account per role on its plus addresses: `you+director@`, `you+coordinator@`,
`you+compliance@`, `you+member@` and `you+agency@` (principal of a small demo agency). Their
passwords are random, new on every run and printed once in the seeder output.

Background work, which the platform runs in production:

```bash
php artisan queue:work
php artisan schedule:work
```

`DatabaseSeeder` adds reference data only (roles and permissions, the API Service system account) and is safe everywhere. `DemoSeeder`
refuses to run unless `DEMO_SEED_ALLOWED=true`, which is never set on the platform.

## Tests

```bash
php artisan test
vendor/bin/pint --test
```

Tests use SQLite in memory and a fake Blob disk. `AzuriteIntegrationTest` runs only when
Azurite is up. The pipeline does not run the suite yet, so run it before every push.

## Settings

Production values are App Service settings and Key Vault references (root `README.md`,
"Settings you get"). Application settings beyond the platform's, all listed in
`.env.example` with empty values:

| Setting | Purpose |
|---|---|
| `BLIND_INDEX_KEY` | HMAC key for blind indexes of encrypted fields (separate from `APP_KEY`) |
| `DOCUMENTS_ENCRYPTION_KEY` | App-level encryption of license and tax documents (falls back to `APP_KEY`) |
| `API_SERVICE_KEY` | API key for external callers of `/api/service/*` (later `/api/intake/*`); `php artisan app:api-key:rotate`, quarterly. Not needed by the Stripe webhook |
| `STRIPE_WEBHOOK_SECRET`, `PAYMENT_PORTAL_URL`, `PAYMENT_GRACE_DAYS` | Stripe membership payment status |
| `STRIPE_PRICE_AGENCY_*` | Optional Stripe price IDs for agency membership (lookup keys otherwise) |
| `AZURE_CLIENT_ID` | Client ID of a user-assigned managed identity, if one is used |
| `NOVA_LICENSE_KEY` | Nova licence key |
