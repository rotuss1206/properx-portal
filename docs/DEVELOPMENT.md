# Local development

## Setup

```bash
composer install            # also publishes Nova assets (nova:publish)
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seeder=DemoSeeder   # fictional data; needs DEMO_SEED_ALLOWED=true in .env
```

Changing npm dependencies: regenerate `package-lock.json` with Node 22 / npm 10, the toolchain of
the KEENEY image (`node:22.20.0-trixie-slim`), because the build runs `npm ci` and refuses a lock
that is out of sync:

```bash
docker run --rm -v "${PWD}:/app" -w /app node:22.20.0-trixie-slim npm install --package-lock-only
docker run --rm -v "${PWD}:/app" -w /app node:22.20.0-trixie-slim npm ci --no-audit --no-fund
```

Without Docker, run the same two npm commands with a portable Node 22.20.0 (nodejs.org zip) first on PATH.

`DatabaseSeeder` adds reference data only (roles and permissions, the API Service account). These steps are manual and local only. On the KEENEY platform `APP_KEY` comes from Key Vault and
migrations run in the platform's deploy step, so no Composer script generates keys or migrates.

Run alongside the web server:

| Process | Command | Why |
|---|---|---|
| Azurite (Azure Blob emulator; install once: `npm install -g azurite`) | `npm run azurite`, then once `php artisan app:storage:init` | Documents and the certificate cache (Blob only) |
| Queue worker | `php artisan queue:work` | Reminder emails are queued |
| Scheduler | `php artisan schedule:work` | Nightly `app:check-license-expirations` |

Mail: `MAIL_MAILER=log` locally (messages are written to the log). Production uses Azure Communication Services (`azure` mailer) with `KEENEY_ACS_CONNECTION_STRING`, `KEENEY_MAIL_FROM` and `KEENEY_MAIL_FROM_NAME` from the platform.

2FA is mandatory for every Nova user (staff and members). It can be switched off locally with `NOVA_REQUIRE_2FA=false` (ignored outside `APP_ENV=local`).

Tests: `php vendor/bin/phpunit` (SQLite in memory, faked Blob disk; `AzuriteIntegrationTest` runs only when Azurite is up).

## Reference data (roles, permissions, service account)

Roles, permissions and the API Service account ship as **migrations**, because the KEENEY pipeline runs
only `php artisan migrate --force` and never a seeder. The single source is `App\Services\ReferenceData`;
the `seed_reference_data` migration and `DatabaseSeeder` (`RolesAndPermissionsSeeder`,
`ServiceAccountSeeder`) all call it. It only adds: missing roles, permissions and grants are created,
nothing is ever removed, and running it again changes nothing.

**Rule for new roles or permissions:** add them to `ReferenceData::ROLE_PERMISSIONS` (or
`EXECUTIVE_ONLY`) and ship a **new** migration whose `up()` calls `app(ReferenceData::class)->seed()`
(never edit an old migration: environments that already ran it would not pick the change up). Its
`down()` deletes nothing. Removing or renaming a permission needs its own explicit migration.

## Demo data and accounts

`DemoSeeder` adds fixed fictional data, without Faker or factories (both are dev-only, so missing from
the platform image), and is idempotent: running it again adds nothing. It refuses to run unless
`DEMO_SEED_ALLOWED=true`.

The `@properx.test` accounts below have the password `password` with `APP_ENV=local` only; in any other
environment they get random passwords nobody knows.

Accounts for a real inbox: set `DEMO_LOGIN_EMAIL=you@example.com` before seeding. `DemoLoginSeeder` then
creates `you+director@`, `you+coordinator@`, `you+compliance@`, `you+member@` and `you+agency@example.com`
(principal of its own two-person "Demo Agency (you)"; each address gets a separate agency) with random passwords that are never shown (seeder output can end up
in platform logs); the seeder lists the email addresses only. To sign in, use "Forgot your password?" on
the login page. If the email does not arrive (e.g. Azure Communication Services is not set up), print the
same one-time link on the server:

```bash
php artisan app:password-link you+director@example.com
```

The link is single use, expires after 60 minutes (`auth.passwords.users.expire`), replaces any earlier
link for that account, is recorded in the audit log (`password_link_created`) and is never written to the
application log. Every account must enrol in 2FA on first login unless `NOVA_REQUIRE_2FA=false` locally.

| Account | Role | Notes |
|---|---|---|
| `director@properx.test` | Executive Director | Everything, including staff and the audit log |
| `coordinator@properx.test` | Membership Coordinator | Members, license documents, reminder log |
| `compliance@properx.test` | Compliance Specialist | Library, tax documents, members read-only |
| `member01@properx.test` | Member | Payment active, full access |
| `member02@properx.test` | Member | Payment past due, within the 14-day grace period (banner) |
| `member03@properx.test` | Member | Past due beyond the grace period: restricted |
| `member04@properx.test` | Member | Canceled: restricted |
| `member05@properx.test` | Member | No Stripe data yet (treated as active) |
| `member06@` … `member30@properx.test` | Member | Fixed mix of license and payment states |
| `api-service@properx.test` | API Service | Machine account, cannot log in (random password) |

## Health probes

| Endpoint | Use | Checks |
|---|---|---|
| `GET /api/health/live` | Kubernetes liveness probe | None (the PHP process answers) |
| `GET /api/health` | Kubernetes readiness probe | Database, cache, queue, documents disk; `503` when any fails |

No authentication. The response only says `ok` or `fail` per check; the reason is written to the log.

## API Service key

The API Service account is a system user (no web login) created by `DatabaseSeeder` as reference
data. Automated work runs and is audited as this account: the Stripe webhook finds it by its role and
needs no key (Stripe is authenticated by its signature).

The API key is for external callers only: `/api/service/*` today, `/api/intake/*` later. The
database stores only its SHA-256 hash; the plain key lives in `API_SERVICE_KEY` (Azure Key Vault in
production).

```bash
php artisan app:api-key:rotate            # prints a new key once; put it into API_SERVICE_KEY
php artisan config:clear
curl -H "Authorization: Bearer $API_SERVICE_KEY" https://properx-portal.local/api/service/whoami
```

Rotate quarterly. The previous key keeps working for `API_KEY_ROTATION_OVERLAP_HOURS` (24) so Key Vault
can be updated without downtime; every rotation is recorded in the audit log. After `migrate:fresh --seeder=DemoSeeder`
the seeder re-registers the key from `.env`, so local calls to `/api/service/*` keep working.

## Stripe payment status webhooks (Spec 7)

The portal never takes payments. It only receives the membership payment status from Stripe at
`POST /api/webhooks/stripe` and verifies every request with `STRIPE_WEBHOOK_SECRET`.

Handled events: `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`,
`invoice.payment_failed`. Members are matched by `stripe_customer_id`, otherwise by the invoice
`customer_email` (the customer id is then stored on the profile). Unmatched events are logged with a warning.

### Testing locally with the Stripe CLI

1. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) and run `stripe login` (test mode).
2. Forward events to the local site. `--skip-verify` is needed because the local HTTPS certificate is self-signed:

   ```bash
   stripe listen --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify \
       --events customer.subscription.updated,customer.subscription.deleted,invoice.paid,invoice.payment_failed
   ```

3. Copy the printed signing secret (`whsec_...`) into `.env` as `STRIPE_WEBHOOK_SECRET`, then run `php artisan config:clear`.
4. Trigger events in another terminal. Overriding the customer email makes the event match a seeded member:

   ```bash
   stripe trigger invoice.payment_failed --override customer:email=member01@properx.test
   stripe trigger invoice.paid --override customer:email=member01@properx.test
   ```

   A trigger without an override creates a new Stripe customer that matches nobody: the webhook answers
   `{"outcome":"unmatched"}` and a warning is written to `storage/logs/laravel.log`.

5. Check the result:
   - Nova → Member Profiles → member01 → **Membership Payment** panel (status, updated at, Stripe customer id);
   - Nova → Audit Log: `payment_status_changed` and `stripe_event_processed` by `api-service@properx.test`;
   - the `stripe listen` output shows the HTTP status and the response body (`outcome`).

6. Idempotency: resend an event that was already processed; the response is `{"outcome":"duplicate"}` and nothing changes:

   ```bash
   stripe events resend evt_XXXXXXXXXXXX
   ```

### Outcomes

| Response `outcome` | Meaning |
|---|---|
| `updated` | Payment status changed |
| `unchanged` | Event matched, status already the same |
| `stale` | Older than the last applied event (Stripe does not guarantee order) |
| `duplicate` | Event id already processed |
| `unmatched` | No member found; logged as a warning |
| `ignored` | Event type not handled |

HTTP `400` means an invalid or expired signature; `503` means `STRIPE_WEBHOOK_SECRET` or the API Service account is missing (Stripe retries).

### Access rules

- `past_due`: full access plus a banner with the payment link (`PAYMENT_PORTAL_URL`) for `PAYMENT_GRACE_DAYS` (14) days.
- `canceled`, or past due after the grace period: login still works, but only the profile, notification settings and tax documents are available. Certificate, library and license documents are closed with an explanation, and the public verification page shows the membership as not active.

## Syncing to the KEENEY repository

The platform repository (`keeneycs-shn-portal` in Azure DevOps) receives the application from
this repository. With the clone at `D:\WORK\Avedsoft\Kevin\Portal\keeneycs-shn-portal`:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\sync-to-keeney.ps1 -DryRun   # what would change
powershell -ExecutionPolicy Bypass -File scripts\sync-to-keeney.ps1           # sync and check
```

- Commit here first: the script copies what git sees (tracked files and untracked files that are
  not ignored) from `app/`, `bootstrap/`, `config/`, `database/`, `lang/`, `public/` (without
  `public/vendor/nova`), `resources/`, `routes/`, `tests/`, the `storage/` skeleton,
  `docs/PORTAL.md` and the root project files.
- The clone mirrors those paths: files deleted here are deleted there. Platform files (README.md,
  Dockerfile, pipelines, `site.json`, `.gitignore`, `.gitattributes`, ...) are never touched;
  editor and assistant files, `docs/` (except PORTAL.md), `scripts/`, `.env` and `auth.json` are
  never copied.
- Afterwards it fails on forbidden terms, CRLF line endings, `.env` or `auth.json` in the clone,
  then prints the clone's `git status` and a suggested commit message named after the changed
  areas (e.g. "Update agency membership and certificates"; "Update portal application" when the
  areas are unclear). It never commits or pushes: review, commit and open the pull request in
  the clone yourself.
- Options: `-Target <path>` for another clone location, `-Git <path to git.exe>`.
