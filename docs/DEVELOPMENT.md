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
| `member01@properx.test` | Member | Payment active, full access; membership renews in 76 days ("Renewal due" card) |
| `member02@properx.test` | Member | Payment past due, within the 14-day grace period (banner) |
| `member03@properx.test` | Member | Past due beyond the grace period: restricted |
| `member04@properx.test` | Member | Canceled: restricted |
| `member05@properx.test` | Member | No Stripe data yet (treated as active) |
| `member06@` … `member30@properx.test` | Member | Fixed mix of license and payment states |
| `api-service@properx.test` | API Service | Machine account, cannot log in (random password) |

## Agency enrollment (/join/agency)

Public wizard, no login: https://properx-portal.local/join/agency. It answers 404 unless
`AGENCY_ENROLLMENT_ENABLED=true` (set it in the local `.env`; off on the platform until screens 5-6 exist). Useful links:

| Link | Opens |
|---|---|
| `/join/agency` | Screen 1 (who is joining, partner code) |
| `/join/agency?partner=CODE` | Screen 1 with the partner code filled in (QR links of MGA/FMO partners) |
| `/join/agency?tier=agency-plus` | Screen 2 with the tier preselected (`agency`, `agency-plus`, `agency-enterprise`, or the tier values from `config/agency.php`) |

Partner codes are managed in Nova (Agencies → Partner Codes). "Just me" links to
`INDIVIDUAL_MEMBERSHIP_URL`. Answers are saved encrypted after each step
(`agency_registration_drafts`); the session keeps the draft id, so a new browser session starts over
(the "continue later" email comes with Option B). Screens 5 and 6 are placeholders.

Livewire runs in CSP-safe mode and needs no `npm run build`.

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

Handled events: `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`,
`invoice.payment_failed`. Members are matched by `stripe_customer_id`, otherwise by the invoice
`customer_email` (the customer id is then stored on the profile). Unmatched events are logged with a warning.

The end of the paid period (`member_profiles.paid_through_at`, shown in the Membership Payment panel and used by
the sidebar's "Renewal due" card) comes from the subscription items' `current_period_end`
(`customer.subscription.created` / `.updated`) or the paid invoice lines' `period.end` (`invoice.paid`). It only moves
forward: an older event never shortens it. `agencies.paid_through_at` exists but is filled once agency customers are
matched (Stripe work for agencies).

### Testing locally with the Stripe CLI

1. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) and run `stripe login` (test mode).
2. Forward events to the local site. `--skip-verify` is needed because the local HTTPS certificate is self-signed:

   ```bash
   stripe listen --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify \
       --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,invoice.paid,invoice.payment_failed
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
| `updated` | Payment status changed, or the paid period moved forward |
| `unchanged` | Event matched, status already the same |
| `stale` | Older than the last applied event (Stripe does not guarantee order) |
| `duplicate` | Event id already processed |
| `unmatched` | No member found; logged as a warning |
| `ignored` | Event type not handled |

HTTP `400` means an invalid or expired signature; `503` means `STRIPE_WEBHOOK_SECRET` or the API Service account is missing (Stripe retries).

### Access rules

- `past_due`: full access plus a banner with the payment link (`PAYMENT_PORTAL_URL`) for `PAYMENT_GRACE_DAYS` (14) days.
- `canceled`, or past due after the grace period: login still works, but only the profile, notification settings and tax documents are available. Certificate, library and license documents are closed with an explanation, and the public verification page shows the membership as not active.

## Nova sidebar

The left column of every signed-in Nova page is the ProPerx sidebar:

- Items and access: `App\Nova\Menu\SidebarMenu` (`Nova::mainMenu`), per role, each item behind Nova's resource
  authorization or the profile policy. A new Nova resource must be added there to appear in the sidebar.
  "Billing" stays hidden until `PORTAL_BILLING_PATH` names its page.
- Drawing: `resources/js/nova/sidebar.js` + `resources/css/nova/sidebar.css` (Nova layout rules; served by
  `Nova::script` / `Nova::style`, signed-in users only). The sidebar's own styles (`public/portal/sidebar.css`), the
  icons (`public/portal/img/icons.svg`) and the design tokens (`public/portal/tokens.css`) are shared with the member
  area; Nova gets them from `resources/views/vendor/nova/partials/meta.blade.php`. The script replaces Nova's
  `MainMenu` component with one built from render functions (the CSP has no `unsafe-eval`); it hides nothing itself.
  If it does not load or throws, Nova's own menu renders and the layout overrides switch off (`html.ppx-shell` is removed).
- The collapsed state is kept in `localStorage` (`ppx-sidebar-collapsed`) of that browser only.
- "Renewal due" card: `App\Support\RenewalNotice` (members only, paid period ends within 90 days; agency people
  follow the agency and only the principal sees it). "Renew now" opens `PAYMENT_PORTAL_URL`.

The header is shared with the member area too: `resources/js/nova/header.js` swaps Nova's `GlobalSearch` (page
title, date, Nova's search for staff), `UserMenu` (Profile, Security, Sign out) and `ThemeDropdown` (removed);
`resources/css/nova/header.css` restyles the header and Nova's bell (only while `html.ppx-nova-header` is set);
shared styles in `public/portal/header.css`, data from `App\Support\PortalHeader`. If it fails, Nova's header returns.

**After every Nova upgrade, check the sidebar and the header** (they depend on Nova's internal structure):

1. The component is still registered as `MainMenu`, and the store getters `mainMenu` / `mainMenuShown` and the
   mutation `toggleMainMenu` still exist (`resources/js/store` in Nova).
2. `layouts/AppLayout.vue` still wraps the desktop menu in a `lg:absolute … w-60` column and the content in `lg:ml-60`,
   and `layouts/MainHeader.vue` still has the `lg:w-60` logo block and the `max-w-xxs` mobile drawer: the selectors in
   `resources/css/nova/sidebar.css` rely on these classes.
3. Panels still carry `dusk="<slug>-panel"` (Certificate and Notifications scroll to `membership-certificate-panel` and
   `notification-preferences-panel`).
4. Header: `GlobalSearch`, `UserMenu` and `ThemeDropdown` are still global components rendered by
   `layouts/MainHeader.vue`, the bell button still has `dusk="notifications-dropdown"`, the store action `logout`
   still exists, and pages still set their title through Inertia's `<Head>` ("<title> - <app name>").
5. Sign in as `member01@properx.test`, a staff account and on a phone width: the sidebar, the active item, collapse,
   the mobile drawer, the Renewal due card, the header title, search (staff), bell and account menu all work, and
   the browser console shows no "ProPerx sidebar failed" or "ProPerx header failed" message. If either fails, Nova's
   own menu or header shows instead, so nothing breaks while it is fixed.

## Member area (/member)

The new member area (plan: `docs/specs/member-area.md`) is Blade and Livewire 4 under `/member`, separate from
Nova. Done: the skeleton (stage 1) and the dashboard (stage 2: Overview, Documents, Activity tabs, notifications,
license upload); the other pages still say "Coming soon".

Demo data for the dashboard (DemoSeeder, member01-member05, filled in for existing data too): license verification
(member01 verified, member03 needs attention), in-app notifications, sign-ins, a certificate and downloads in the
activity, and two paid invoices per year with amounts. Notifications are marked read when the dashboard shows them; to
see the bell count again, run `php artisan tinker --execute "DB::table('notifications')->update(['read_at' => null]);"`.
Renewal notices: `php artisan app:send-renewal-notices` (scheduled daily).

- Switch: `MEMBER_AREA_ENABLED=true` in `.env`, then `php artisan config:clear`. Off (the default and the platform
  setting until it is finished), `/member` answers 404 and members use Nova as before. `phpunit.xml` pins it off; tests
  turn it on themselves.
- With it on: sign in at https://properx-portal.local/nova/login as `member01@properx.test` (password `password`)
  and you land on https://properx-portal.local/member. Members opening a Nova page are sent there too, except User
  Security (password, two-factor) and signing out, which stay Nova's. Staff (`director@properx.test`) still land in
  Nova, and `/member` sends them back to it.
- Access: `routes/member.php`, middleware `EnsureMemberArea` (404 while off, staff to Nova, Member role only; it runs
  before `auth`, see `bootstrap/app.php`) and `RequireTwoFactor`. Nova's side: `SendMembersToMemberArea` in
  `nova.api_middleware`.
- Menu: `App\Member\MemberMenu` (agency people see no License documents; only the principal sees Billing). The sidebar
  reuses the Nova sidebar's classes (`public/portal/sidebar.css`) and the Renewal due card and person from
  `App\Support\PortalSidebar`.
- Files: `resources/views/components/member/*` (layout, sidebar, header, icon), `public/portal/member.css` and
  `member.js` (collapse, mobile drawer, account menu; DOM APIs only, loaded with the CSP nonce).

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
