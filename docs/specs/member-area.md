# Member area (`/member`): plan

Status: **stages 1 (skeleton) and 2 (dashboard) done; stages 3-8 to do. Decisions: section 8.** Stage 2 notes: the License card became "License verification" (`member_profiles.license_verification`, NPN and state, no expiry date) and CE credits became a Certificate card; the timeline's "Renewed" is one year before `paid_through_at` (no period start is stored); no Export log. The new member area replaces Nova for the Member role. Nova stays the
staff panel. Mockups: `docs/design/*.jpg`; icons: `docs/design/icons/*.svg` and the icons of the Nova
sidebar (`resources/js/nova/sidebar.js`). Built with Blade and Livewire 4 like `/join/agency`, under
`/member/*`, behind `MEMBER_AREA_ENABLED` (off by default).

Mockups available: Dashboard (Overview, Documents, Activity, Benefits tabs), Profile (`Main.jpg`),
Billing, License documents (`Documents Management.jpg`), Upload (`Container.jpg`), Certificate page
(`Certificate Generate.jpg`) and the certificate itself (`Certificate.jpg`). **No mockups** for Tax
documents, Compliance library and Notifications: they are composed from the shared components below.

**Rule: the whole interface, the member area and Nova, ends up looking like the mockups
(`docs/design/*.jpg`).** Deviations only where we deliberately replaced data (License verification, a
Certificate card instead of CE credits, Benefits and search hidden), and then in content, never in style.
Every stage: screenshots at 1440 / 1100 / 390 px, also with classic (always visible) scrollbars as on
Windows; the mockup side by side; fixes until they match. The report lists the screenshot paths and the
differences that remain, each with its reason.

---

## 1. Design tokens

Measured from the mockups (1440 px wide) and aligned with what the sign-in page
(`public/portal/login-page.css`) and the Nova sidebar (`resources/css/nova/sidebar.css`) already use.
They move into **one file, `public/portal/tokens.css`**, loaded by the sign-in page, the Nova sidebar
and the member area; the existing files then use the variables instead of their own literals
(stage 1, no visual change).

### Colours

```css
:root {
  /* Brand */
  --ppx-navy: #0a2d4b;            /* sidebar, dark text on yellow */
  --ppx-navy-700: #0f3b66;        /* hero cards (gradient start) */
  --ppx-navy-500: #16528f;        /* hero cards (gradient end), primary button "Download" */
  --ppx-blue: #14508c;            /* links, active tab, focus ring, selected chip */
  --ppx-blue-50: #e4edf7;         /* selected table row, info boxes ("Your documents are secure", auto-renewal) */
  --ppx-blue-100: #c9dcf3;        /* certificate live preview background */
  --ppx-yellow: #ffd24d;          /* active menu item, primary buttons, "Founding member" badge */
  --ppx-yellow-600: #f6c731;      /* yellow hover */
  --ppx-green: #4e9a2c;           /* card title accent line, success icons, progress */
  --ppx-green-50: #eaf4e1;        /* success badge background, upload drop zone tint */
  --ppx-green-700: #3f7d22;       /* success badge text, outline "Browse files" button */

  /* Neutrals */
  --ppx-ink: #1c2b3a;             /* headings and values */
  --ppx-text: #33475b;            /* body text */
  --ppx-muted: #5f7185;           /* labels, subtitles, captions */
  --ppx-subtle: #8a99aa;          /* secondary lines under a document name, placeholders */
  --ppx-border: #cdd6e0;          /* inputs */
  --ppx-line: #e3e8ef;            /* card borders, table row dividers */
  --ppx-surface: #ffffff;         /* cards */
  --ppx-page: #eef2f7;            /* page background */
  --ppx-table-head: #f6f8fb;      /* table header row */

  /* Status badges (dot + text on a tint) */
  --ppx-badge-success-bg: var(--ppx-green-50);   --ppx-badge-success-fg: var(--ppx-green-700);   /* Approved, Available, Ready, Current, Paid, Enrolled, Validated */
  --ppx-badge-warning-bg: #fff3cf;               --ppx-badge-warning-fg: #9a6700;                /* Pending, Expiring soon (license card) */
  --ppx-badge-danger-bg:  #fde8e8;               --ppx-badge-danger-fg:  #c0362c;                /* Rejected, Past due, Expired */
  --ppx-badge-neutral-bg: #eef1f5;               --ppx-badge-neutral-fg: var(--ppx-muted);       /* Available (benefit not enrolled) */

  /* Sidebar (already in sidebar.css; kept as aliases) */
  --ppx-sidebar-bg: var(--ppx-navy);
  --ppx-sidebar-text: #b4c4d6;
  --ppx-sidebar-icon: #6f8db0;
  --ppx-sidebar-active: var(--ppx-yellow);
  --ppx-sidebar-ink: var(--ppx-navy);
}
```

### Typography

| Use | Font | Size / weight |
|---|---|---|
| Page title in the header ("Dashboard") | Poppins | 20 px / 600 |
| Greeting ("Good morning, Jordan"), page heading | Poppins | 24 px / 600 |
| Card title ("Recent documents") | Poppins | 15 px / 600 |
| Hero value ("Active", "$199", name on the profile banner) | Poppins | 26–30 px / 600–700 |
| Body, table cells, values | Open Sans | 14 px / 400 |
| Labels in key–value rows, captions | Open Sans | 13 px / 400, `--ppx-muted` |
| Table header, eyebrow ("MEMBERSHIP", "CURRENT PLAN") | Open Sans | 11 px / 600, uppercase, letter-spacing .08em |
| Badges | Open Sans | 12 px / 600 |
| Buttons | Poppins | 14 px / 600 |

**Font check:** `public/portal/fonts` has Poppins 500/600/700 and Open Sans 400/500/600 (woff2,
Latin), which covers every screen of the area. Missing:

- **A serif for the certificate** (name and signatures look like *Lora* italic; the eyebrow
  "CERTIFICATE OF MEMBERSHIP" is Poppins 600 with wide tracking). Lora is under the SIL OFL; it needs a
  woff2 for the on-screen preview and a **TTF** for the PDF.
- **TTF files for dompdf.** dompdf cannot read woff2, so the PDF needs Poppins, Open Sans and Lora as
  TTF inside its chroot (`resources/views/pdf/fonts`, next to DejaVu today).

### Sizes, radii, shadows, spacing

```css
:root {
  --ppx-sidebar-width: 248px;  --ppx-sidebar-collapsed-width: 76px;   /* as in sidebar.css */
  --ppx-header-height: 62px;
  --ppx-aside-width: 320px;                 /* right column on the dashboard (notifications, upload) */
  --ppx-content-max: 1440px;

  --ppx-radius-sm: 6px;    /* badges are pills: 999px */
  --ppx-radius: 10px;      /* inputs, buttons, menu items, chips */
  --ppx-radius-lg: 14px;   /* cards, hero cards, drop zone */

  --ppx-shadow-card: 0 1px 2px rgba(16, 42, 75, .04);
  --ppx-shadow-hero: 0 8px 24px rgba(15, 59, 102, .25);
  --ppx-shadow-button: 0 8px 18px rgba(240, 186, 30, .32);   /* yellow primary, as on the sign-in page */

  --ppx-space-1: 4px;  --ppx-space-2: 8px;  --ppx-space-3: 12px;  --ppx-space-4: 16px;
  --ppx-space-5: 20px; --ppx-space-6: 24px; --ppx-space-8: 32px;
  /* Page padding 24px 26px; gap between cards 20px; card header 20px 22px; key–value rows 14px 22px. */
}
```

Breakpoints as on the sign-in page and in the Nova sidebar: ≤ 900 px the right column drops under
the content and the sidebar becomes a drawer opened by a header button; ≤ 600 px phone layout
(tables turn into stacked rows).

---

## 2. Shared components

Blade components under `resources/views/components/member/`, styles in `public/portal/member.css`
(classes only, no inline styles; the CSP allows inline styles, but the codebase does not use them),
icons from one SVG sprite.

| Component | Notes |
|---|---|
| `layout` | Sidebar (left), header, main column, optional right column (`<x-slot:aside>`). |
| `sidebar` | Same markup and classes as the Nova sidebar (`ppx-sidebar__*`): logo, collapse (same `localStorage` key `ppx-sidebar-collapsed`), "MENU", items, Renewal due card (`App\Support\RenewalNotice`), person (`App\Support\PortalSidebar`). The `.ppx-sidebar` styles move from `resources/css/nova/sidebar.css` into `public/portal/sidebar.css`, shared by Nova and the area; Nova keeps only its layout overrides. Items come from `App\Member\MemberMenu` (same order and visibility rules as `SidebarMenu::memberItems`, one source for both). |
| `header` | Page title or breadcrumb ("License documents / Upload document"), today's date (`PORTAL_SCHEDULE_TIMEZONE`), bell with unread count (opens the notifications panel), avatar menu (Profile, Security, Sign out). Search (`member_area.header_search = true`): the member's own documents and the published library, suggestions under the field, Enter opens the results page; the query is sent by POST and kept in the session, never in a URL or a log. |
| `icon` | `<svg><use href="/portal/img/icons.svg#name"/></svg>`, `currentColor`. One sprite (`public/portal/img/icons.svg`) built from the Nova sidebar paths and `docs/design/icons`; the Nova sidebar switches to the same sprite, so the paths live in one place. |
| `card` | White, `--ppx-radius-lg`, title with the 3 px green bar on the left, optional header action (link "View all", outline button "Edit"), optional footer. Variants: `hero` (navy gradient, white text), `info` (`--ppx-blue-50`). |
| `kv-list` | Label / value rows of the profile and the document panel. |
| `table` | Document tables: icon tile + name + secondary line, type, status badge, date, action (download / view / chevron). Header row `--ppx-table-head`; selectable rows (document panel); stacked on phones; built-in empty state. |
| `badge` | Pill with a dot: `success`, `warning`, `danger`, `neutral`. Enum → variant map in PHP (`App\Member\Badges`). |
| `button` | `primary` (yellow), `secondary` (navy, e.g. "Download"), `outline`, `ghost`, `icon` (square, e.g. replace / delete with danger tint), `link`. Loading state for Livewire actions. |
| `chips` | Filter pills ("All documents", "Licenses"…); selected = navy fill. |
| `tabs` | Underlined tabs (dashboard); real links (`?tab=`), so each tab is addressable and works without JS. |
| `dropzone` | Dashed border, icon tile, "Drag & drop", "Browse files", accepted types and size from `DocumentVault::uploadRules()`; a file list with progress, validated and rejected states. |
| `empty-state` | Icon, one sentence, optional action. |
| `toggle`, `select`, `radio-card` | Certificate options, notification preferences. |
| `timeline` | Membership timeline (joined, renewed, today, next renewal). |
| `notice` | Flash and inline messages (success / error), same toast rules as the sign-in page (one at a time). |

---

## 3. Page map

All pages: Member role, own data only, every query through the existing policies and
`ownRecordsUnless`-style scopes (a member never addresses another member's record by id; ids in URLs
are re-authorized like Nova does). "Agency people" = profiles with `agency_id`.

### `/member` — Dashboard (`?tab=overview|documents|activity|benefits`)

Greeting "Good morning, {first_name}" (time of day in `PORTAL_SCHEDULE_TIMEZONE`).

**Overview**

| Block | Data | Notes |
|---|---|---|
| Membership (hero) | `MemberProfile::payment_status` (Active / Past due / Canceled / Unknown), "Renews {paid_through_at}" (agency people: the agency's `paid_through_at`) | "Founding member" badge: agency people → agency `rate_type = founding`; individuals → hidden (no source; `member_area.founding_badge_individuals = false` for later). |
| License | `license_expires_at`, `license_status` (Current / Expiring / Expired) | Agency employees: card replaced by "Not required for your role". |
| CE credits | — | **Not our data** (out of scope, Spec). Hidden: `portal.member_area.ce_credits = false`. |
| Membership timeline | Joined = `users.created_at` (or the agency's enrollment date); Renewed = last `invoice.paid` matched to the member (`stripe_events`); Today; Next renewal = `paid_through_at`. Cycle label from the period. | Hidden while `paid_through_at` is null. |
| Recent documents | Last 3 of: license documents, tax documents, the certificate (last `certificate_generated` audit entry) | Benefit documents: no source, not shown. |
| Right column: Notifications | See "Notifications feed" below. | |
| Right column: Upload a document | Shortcut to the license upload (individual members only). | Hidden for agency people. |

**Documents** — one table of license documents (`verification_status`), tax documents (always
"Available"), certificate ("Ready" when eligible, else the reason); chips All / License / Tax /
Certificate. "Benefits" chip and rows: no source, hidden.

**Activity** — the member's own events, newest first, 20 per page:

| Line | Source |
|---|---|
| License document approved / rejected / uploaded / deleted | `audit_logs` (`document_verified`, `document_rejected`, `document_uploaded`, `document_deleted`) on the member's documents |
| Membership certificate generated / downloaded | `audit_logs` (`certificate_generated`, `certificate_downloaded`) |
| License reminder sent | `reminder_logs` (sent only) |
| Tax document available | `tax_documents.uploaded_at` |
| Membership payment processed ("Annual plan · $199.00") | `stripe_events` (`invoice.paid` linked to the profile) with **amount, currency and plan name stored from the event** (new nullable columns, not PHI); no Stripe API calls |
| Profile updated, password changed, two-factor enabled | `audit_logs` (`profile_updated` with field names, `password_changed`, `mfa_enrolled`) |

"Export log" = CSV of the same list (no IP addresses; own data only; audited as `activity_exported`).
The mockup's "Renewal reminder sent" is not an event we have (Stripe sends renewal emails); the
computed "Renewal due" lives in the notifications feed instead.

**Benefits** — catalogue from `config/portal.php` (`member_area.benefits`: title, description, icon,
optional URL). Statuses ("Enrolled", "Pending") are **not our data**: hidden behind
`member_area.benefit_statuses = false`; cards show "Available" or nothing.

### Notifications feed (bell, dashboard right column, `/member/notifications` top)

**Decided:** Laravel's standard database notification channel (new `notifications` table with
`read_at`, expand-only), written next to the existing emails and on new events: license document
approved / rejected, new tax document, license reminder, renewal due (90/30/7 days, from
`paid_through_at`). Payload holds ids and enum values only, no PHI; texts are rendered at display
time; emails stay PHI-free as today. Unread count in the bell; "mark all read" sets `read_at`.

**Built (stage 2 follow-up):** the right column is a global panel opened by the bell on every member page and in
Nova (staff see Nova's own notifications there): a pushed column at >= 1280 px, an overlay at 1024-1279 px, a
full-screen sheet below. Open by default on the dashboard only; nothing is marked read by showing it (opening one or
"Mark all read" does). The license upload sits under the notifications on the dashboard, for individual members only.

### `/member/profile`

| Card | Fields | Source / editing |
|---|---|---|
| Banner | Initials, full name, "Licensed insurance agent · {firm}", Member #{member_number}, payment status, Founding badge | `PortalSidebar`, `MemberProfile` |
| Contact information | Full name, email, phone, mailing address | `first_name`, `last_name`, `users.email`, `phone`, `address` (one text field today; shown on two lines if it contains a line break). Edit inline (Livewire), same rules as the Nova `MemberProfile` resource; **email is read-only** (it is the login; staff change it). |
| Business details | Agency name, role, business type, NPN | Individuals: `firm_name`, NPN editable as in Nova. Agency people: the agency's legal name, `agency_role` / `person_type`, agency type, read-only (staff change them). |
| License information | Number, type, resident state, expiration + status badge | Individuals: `license_*` fields. Agency people: **NPN + resident state + verification status only** (Agency spec 3.5; license number, type, expiry hidden, columns kept). |
| Security | Password (last changed), two-factor (Enabled), recovery codes (n of 8 remaining), "Manage" | Last change = latest `password_changed` audit entry; two-factor from Fortify columns; remaining codes = count of the decrypted list. "Manage" opens Nova's `/nova/user-security` (Fortify endpoints already work for members) until an own page is built (stage 8 option). |

Every save is audited by `MemberProfileObserver` (field names only), exactly as from Nova.

### `/member/billing` (individual members and agency principals)

Whose subscription: an individual member → their own Stripe customer
(`member_profiles.stripe_customer_id`); an **agency principal → the agency's** Stripe customer and
subscription (`agencies.stripe_customer_id` / `stripe_subscription_id`). Other agency people get no
Billing page or menu item (404).

| Block | Source |
|---|---|
| Current plan (name, price, interval, Next payment, Billing cycle, Member since) | **Stripe API**: the active subscription (price nickname or product name, `unit_amount`, `recurring.interval`, items' `current_period_end`), `customer.created` or `users.created_at`. Principal: plan = the agency's tier and rate type, plus **number of people** (subscription item quantity, or `agencies.headcount`) and the per-person rate. |
| Payment method (brand, last 4, expiry) | Stripe API: the customer's default payment method. "Update" → Stripe Customer Portal session. |
| Invoice history (number, date, amount, status, PDF) | Stripe API: invoices of the customer (last 12). "PDF" opens Stripe's `invoice_pdf` link in a new tab. No "Download all": the card header links **"View all in Stripe"** to a Customer Portal session. |
| Auto-renewal | Subscription `cancel_at_period_end`; "Manage renewal" → Customer Portal session. |

Needs a new secret `STRIPE_SECRET` (restricted key: read customers, subscriptions, invoices, payment
methods; write billing portal sessions). Nothing from Stripe is stored or cached (section 6). Without a
Stripe customer (e.g. an agency before the agency Stripe work links its customer): an empty state with
the `PAYMENT_PORTAL_URL` link.

### `/member/license-documents` and `/member/license-documents/upload` (individual members only)

List (mockup "Documents Management"): **license documents only** (the Tax / Certificate chips live in
the dashboard Documents tab), search in PHP over the member's own few documents (`original_filename`
is encrypted, no SQL search), status badge (`verification_status`: Approved / Pending / Rejected; no
"Expiring", documents carry no expiry date), document type, uploaded date, side panel with status,
type, the profile's resident state, uploaded, size and format, Download (existing signed download
route), Replace (upload a new document) and Delete (only while pending, as `LicenseDocumentPolicy`
allows).

Preview: images inline through the signed download route; **PDF preview is not possible** while the
app sends `X-Frame-Options: DENY` (no iframes) — a file icon is shown instead.

Upload (mockup "Container"): drop zone, uploaded-files list with progress, rejected files (wrong type,
over 10 MB) shown with the reason, document details, Save. **Document details = document type only**:
Resident license / Non-resident license / Other (new nullable column `license_documents.document_type`,
expand-only; existing rows stay empty and show "—"). License number, issue and expiration dates are
**not collected** (current rules: NPN and resident state only); the mockup's fields are dropped. An
approved document never changes the profile.

"Your documents are secure" box: text from config; the line "Automatically scanned for malware on
upload" and the per-file "scanned for viruses" / "Validated" marks only when
`portal.member_area.malware_scan = true` (no scanning today). "Encrypted in transit (TLS 1.3)" is the
platform's claim, so the default text says "Encrypted in transit and at rest".

### `/member/certificate`

Options card: "Include member ID", "Include join date" (both on by default), **no membership term
selector** (only the current paid period exists; past periods are not reissued), **no QR toggle**
(always printed), **format PDF only** (no PNG option shown). Live preview: the certificate rendered as HTML in the page (same Blade
partial as the PDF, scaled), updated by Livewire when options change. "Generate & download" → the
existing `certificates.open` → signed `certificates.download` flow, with the options as part of the
cache key (cached PDF per option set, 24 h, as today). Unavailable (expired license, incomplete
profile, restricted membership): the card shows the reason from `CertificateUnavailableException`.

New certificate design (`Certificate.jpg`): navy and gold double frame with corner marks, client logo,
"CERTIFICATE OF MEMBERSHIP", name in serif italic, the statement, Member ID / Member since / Valid
through, signature line(s) with the official seal, verification line with the URL and certificate
code; QR code added next to the verification line (the mockup shows none). Data:

| Printed | Source |
|---|---|
| Name | `first_name last_name` (no middle name or initial) |
| Member ID | `member_number` (`PPX-M…`) |
| Member since | `users.created_at` (month and year) |
| Valid through | `paid_through_at` (default 4.1); fallback while no Stripe date: `license_expires_at` as today |
| Statement | "…is a recognized member in good standing of ProPerx Alliance, entitled to all rights, benefits, and privileges of membership. **Membership valid through Mar 12, 2027.**" (date = Valid through). No "2026 membership term" wording. |
| Signatures | Executive Director name and title from config; optional second signatory (config), off by default |
| Verification | `route('certificates.verify', certificate_code)`, QR as data URI |

Bump `CertificateGeneratorService::TEMPLATE_VERSION`. Fonts and the seal/logo images inside the dompdf
chroot.

### `/member/tax-documents` (no mockup)

Card with a table: document type (`1099-NEC`…), tax year, uploaded date, status "Available" /
"Downloaded" (`downloaded_at`), download through the existing signed route (audited). Chips per tax
year. Empty state: "Your tax documents will appear here when ProPerx uploads them." Open to restricted
members too (Spec 7).

### `/member/library` (no mockup)

Published `library_documents`: chips per category (`LibraryDocument::CATEGORIES`), search by title
(plain columns), table or card grid with title, description, category, date, download. Restricted
members see the Spec 7 explanation instead (as in Nova today).

### `/member/notifications` (no mockup)

Top: the notifications feed (section above, full list, mark read). Below: "Email preferences" card,
one toggle per `NotificationCategory` (license expiry, compliance briefings, tax documents,
announcements), saved to `notification_settings` with the same policy as the Nova panel
(`manageFor`). The Nova sidebar's "Notifications" item points here once the area is on.

---

## 4. Defaults (all in `config/portal.php`, `member_area` section)

| # | Default | Config key |
|---|---|---|
| 4.1 | Certificate "Valid through" = `paid_through_at`; the term is yearly from the payment date, not a calendar year. | `member_area.certificate.valid_through = 'paid_through'` (`'license_expiry'` restores today's rule) |
| 4.2 | Member ID in our format (`PPX-M000005`), shown as stored. | — (format lives in `MemberProfile::formatMemberNumber`) |
| 4.3 | Second signature off. | `member_area.certificate.second_signatory = null` or `['name' => …, 'title' => …]`; first signatory `member_area.certificate.signatory` (name, title, optional image) |
| 4.4 | Certificate PDF only (no PNG: no image renderer in the container); QR always printed. | — |
| 4.5 | Agency people: license = NPN + state + verification status; no license upload; Billing only for the principal (the agency's subscription). Individuals: fields from current data. | `member_area.agency_people.license_upload = false`, `member_area.agency_people.principal_billing = true` |
| 4.6 | No "scanned for malware" wording until scanning exists. | `member_area.malware_scan = false` |
| 4.7 | CE credits and benefit statuses hidden. | `member_area.ce_credits = false`, `member_area.benefit_statuses = false`; catalogue `member_area.benefits = [...]` |
| 4.8 | Billing from the Stripe API; card update, renewal management and the full invoice list in the Stripe Customer Portal. | `STRIPE_SECRET`, optional `STRIPE_BILLING_PORTAL_CONFIGURATION`, return URL `/member/billing` |
| 4.9 | The area is off. | `member_area.enabled = env('MEMBER_AREA_ENABLED', false)` |
| 4.10 | "Founding member" badge hidden for individual members (shown for agency people of a founding-rate agency). | `member_area.founding_badge_individuals = false` |
| 4.11 | Header search: own documents and the library only (no site-wide search). | `member_area.header_search = true` |
| 4.12 | License upload asks only for the document type. | `member_area.license_document_types` (resident, non-resident, other) |

---

## 5. Sign-in, access and the switch

- **Sign-in stays shared**: Nova's login (already in the new design), 2FA challenge, password reset.
- **Routes** `routes/member.php`, prefix `/member`, middleware `web`, `auth`, `RequireTwoFactor` (as
  today: without confirmed 2FA only `/nova/user-security` is reachable, where enrolment happens), and
  `member-area` (new): area enabled, user has the Member role and no staff role; otherwise 404 (area
  off) or redirect to `/nova` (staff).
- **After sign-in**, with the area on: a middleware on Nova's page routes (`config/nova.php`
  `middleware`) sends members' `GET /nova/*` page requests to `/member`, except `/nova/user-security`
  (2FA, password), `/nova/logout`, the 2FA challenge and password reset pages. Staff keep landing on
  `/nova/dashboards/main`. Members' `nova-api` requests answer 403 once stage 8 closes Nova for them.
- **While `MEMBER_AREA_ENABLED=false`** nothing changes: members use Nova and its member sidebar as now.
- **Nova's member menu when the area is on**: members never see Nova pages (redirect), so
  `SidebarMenu::memberItems` stays only as the fallback for the switch being off; the Renewal due card
  and the person block keep working for both. Stage 8 removes the redirect exceptions that are no longer
  needed (once Security has its own page, `/nova/user-security` too) and adds tests that a member reaches
  no Nova resource.
- Certificate and document downloads keep their signed routes (`certificates.*`, `documents.download`);
  their redirects back go to `/member/*` instead of Nova pages.

---

## 6. Risks and limits

- **CSP** (`SecurityHeaders`: no `unsafe-eval`, nonces): Livewire stays in `csp_safe` mode; no
  `x-data` expressions that need `new Function`; page scripts are files with the nonce. The Stripe
  Customer Portal is a top-level navigation: if it is reached through a form POST + redirect, the CSP
  `form-action` must allow `https://billing.stripe.com` (Chrome applies `form-action` to redirects); a
  GET link to our route that redirects avoids it. No Stripe.js needed.
- **Uploads on the platform**: Livewire's temporary uploads go to a local temp disk. With several pods
  the save request may reach another pod, and an unencrypted license scan would sit on disk. Plan: a
  plain multipart POST to a controller that hands the file straight to `DocumentVault` (encrypt, Blob),
  with a small CSP-safe script for drag & drop and progress (XHR upload events). Limits and MIME
  sniffing stay `DocumentVault::uploadRules()`.
- **Frames**: `X-Frame-Options: DENY` → no PDF preview in the page (images only).
- **PHI in logs and cache**: no names, emails, NPNs, license numbers or Stripe customer details in logs,
  exception messages (log `$e::class`) or caches. Livewire components hold record ids, never encrypted
  values in public properties (they would travel to the browser in the snapshot); forms load values
  server-side. Activity export and the notification payloads carry ids and enum values only.
- **Stripe API**: never cached (no Redis/file cache of customer, card or invoice data; per-request memo
  only), never logged (log the request id and exception class), short timeouts (5 s) and an error state
  that keeps the page usable; the platform's 120 s limit does not apply to web requests but the page
  must not hang. Rate: one subscription + one payment method + one invoice list call per page view.
- **KEENEY platform**: new env/secrets (`MEMBER_AREA_ENABLED`, `STRIPE_SECRET`) through Key Vault;
  migrations expand-only (`notifications` table, `license_documents.document_type`, payment details on
  `stripe_events`); the pipeline runs
  only `migrate`; fonts (TTF for dompdf, woff2 for the browser) are files in the repo; `/livewire/*` must
  pass the ingress; sessions stay platform-owned (15 minutes): long upload forms must survive a
  session timeout gracefully (redirect to sign-in, keep nothing unencrypted).
- **Two member UIs during the switch**: until stage 8 both Nova and `/member` must enforce the same
  rules (restricted membership, agency people, own records); shared logic goes into services/policies,
  not into Nova resources.
- **Data gaps** (decided in section 8): Founding badge for individuals hidden; payment amounts stored
  from `invoice.paid`; in-app notifications as database notifications; only the document type on
  uploads; no middle name.

---

## 7. Stages and files

Each stage: tests, Pint, commit, push; the area stays behind `MEMBER_AREA_ENABLED` until stage 8.

**1. Skeleton**
- `config/portal.php` (`member_area`), `.env.example` (`MEMBER_AREA_ENABLED=`)
- `routes/member.php`, registered in `bootstrap/app.php`
- `app/Http/Middleware/EnsureMemberArea.php`, `app/Http/Middleware/SendMembersToMemberArea.php` (inactive until stage 8 decides)
- `app/Member/MemberMenu.php` (shared with `App\Nova\Menu\SidebarMenu`), `app/Member/Badges.php`
- `resources/views/member/layout.blade.php`, `resources/views/components/member/{sidebar,header,card,kv-list,table,badge,button,chips,tabs,dropzone,empty-state,icon,notice}.blade.php`
- `public/portal/tokens.css`, `public/portal/sidebar.css` (moved from `resources/css/nova/sidebar.css`), `public/portal/member.css`, `public/portal/member.js` (drawer, collapse, dropdowns; nonce), `public/portal/img/icons.svg`
- `login-page.css`, `resources/css/nova/sidebar.css`, `resources/js/nova/sidebar.js` switched to tokens and the sprite
- Tests: `tests/Feature/Member/AccessTest.php` (off → 404; staff → Nova; member without 2FA → user-security; CSP headers), `MemberMenuTest`

**2. Dashboard**
- `app/Livewire/Member/Dashboard.php` (tabs), `resources/views/livewire/member/dashboard/{overview,documents,activity,benefits}.blade.php`
- `app/Member/ActivityFeed.php` (audit_logs, reminder_logs, tax documents, stripe_events), `app/Http/Controllers/Member/ActivityExportController.php`
- Migration `add_payment_details_to_stripe_events_table` (`amount` in cents, `currency`, `plan_name`; expand), filled by `StripeWebhookHandler` from `invoice.paid` (total, currency, line price nickname or product name)
- Notifications: migration `create_notifications_table`, `app/Notifications/Member/*` (database channel), `resources/views/components/member/notify-panel.blade.php` + `public/portal/notify.js`
- Tests: `DashboardTest`, `ActivityFeedTest` (own events only, no PHI), `NotificationPanelTest`

**3. Profile**
- `app/Livewire/Member/Profile.php` + form objects (`ContactForm`, `BusinessForm`, `LicenseForm`), validation shared with `App\Nova\MemberProfile` (rules extracted to `App\Member\ProfileRules`)
- `resources/views/livewire/member/profile.blade.php`
- Tests: `ProfileTest` (edit and audit, agency people read-only, email read-only, other member's data unreachable)

**4. License documents + upload**
- Migration `add_document_type_to_license_documents_table` (expand), `App\Enums\LicenseDocumentType`, `LicenseDocument` cast, Nova resource shows the type to reviewers
- `app/Livewire/Member/LicenseDocuments.php`, `app/Http/Controllers/Member/LicenseDocumentUploadController.php`, `public/portal/upload.js`
- `resources/views/livewire/member/license-documents.blade.php`, `resources/views/member/license-documents/upload.blade.php`
- Tests: `LicenseDocumentsTest` (list, search, panel, delete only pending, replace), `LicenseUploadTest` (types, 10 MB, sniffed MIME, encryption, audit, agency people refused, restricted members refused)

**5. Certificate**
- `app/Livewire/Member/Certificate.php`, `resources/views/livewire/member/certificate.blade.php`
- `resources/views/pdf/certificate.blade.php` (new design, shared partial for the preview), `resources/views/pdf/fonts/*.ttf` (Poppins, Open Sans, Lora + OFL texts), `resources/views/pdf/images/{logo,seal}.png`, `public/portal/fonts/lora-*.woff2`
- `CertificateGeneratorService` (options in the cache key, valid-through rule, `TEMPLATE_VERSION`), `config/dompdf.php` font registration
- Tests: `CertificatePageTest`, `CertificateGeneratorServiceTest` (valid through = paid_through_at, options, second signatory off/on, QR present)

**6. Billing**
- `composer.json` (no new package: `stripe/stripe-php` is installed), `config/services.php` (`stripe.secret`, portal configuration), `.env.example`
- `app/Services/Stripe/BillingSummary.php` (read-only API calls, DTOs without customer PII; the member's or, for a principal, the agency's customer), `app/Http/Controllers/Member/BillingPortalController.php`
- `app/Livewire/Member/Billing.php`, `resources/views/livewire/member/billing.blade.php`
- `SecurityHeaders` (`form-action` only if needed)
- Tests: `BillingTest` with a faked Stripe client (plan, card, invoices, "View all in Stripe" and portal redirects, principal sees the agency's plan and people, other agency people 404, no customer, API error, nothing logged or cached)

**7. Tax documents, library, notifications**
- `app/Livewire/Member/{TaxDocuments,Library,Notifications}.php` and views
- Tests: `TaxDocumentsTest`, `LibraryTest` (restricted members), `NotificationPreferencesTest`

**8. Review and switching Nova off for members**
- `SendMembersToMemberArea` active; members' `nova-api` access closed except what `/nova/user-security` needs (or an own Security page: `app/Livewire/Member/Security.php` using Fortify's actions)
- `App\Nova\Menu\SidebarMenu` member branch kept only for `MEMBER_AREA_ENABLED=false`
- Redirect targets of certificate/document flows → `/member/*`
- Accessibility pass (keyboard, focus, `aria-current`, contrast of badges), phone layout pass, CSP check in the browser, `docs/PORTAL.md` and CLAUDE.md
- Tests: `MemberNovaAccessTest` (a member reaches no Nova resource or dashboard with the area on; everything as before with it off)

---

## 8. Decisions

| # | Question | Decision |
|---|---|---|
| 1 | "Founding member" badge for individual members | Hidden; `member_area.founding_badge_individuals` for later. Agency people of a founding-rate agency show it. |
| 2 | In-app notifications | Laravel's standard database channel (`notifications` table, `read_at`). No PHI in notifications or emails. |
| 3 | Billing for agency principals | Shown: the agency's subscription (plan, number of people, next payment, invoices, Customer Portal). Hidden for the other agency people. |
| 4 | Payment amounts in Activity | Amount, currency and plan name stored from `invoice.paid` (not PHI); no Stripe API calls for Activity. |
| 5 | "Download all" invoices | Dropped; "View all in Stripe" opens the Customer Portal. |
| 6 | Email in the profile | Read-only. |
| 7 | License documents page | License documents only; Tax / Certificate filters in the dashboard Documents tab. |
| 8 | Upload metadata | Document type only (resident license / non-resident license / other); no license number or dates (current rules: NPN and state only). An approved document does not change the profile. |
| 9 | Middle name on the certificate | Not added; first and last name. |
| 10 | Header search | Shown: own documents (license, tax, certificate) and the library (`App\Member\MemberSearch`). |
| 11 | Certificate wording | "Membership valid through Mar 12, 2027" (date = `paid_through_at`); no "2026 membership term". |
