# Go-live rules: agency minimum and partner rates, license copies, owner welcome, second signature

## Summary

- Agencies always pay for at least 2 members (billable seats = max(2, people)): wizard, review, Stripe quantity,
  adding people, removals at renewal, reminders, receipts. "Minimum 2 members: $159 × 2" is shown. With
  `AGENCY_BILL_EMPLOYEES=false`, W2 employees count toward the minimum only.
- Partner codes: $149 for 2–49 people (one card in the wizard), $129 for 50+, from `config/agency.php`.
- Licensed producers (principal, people, "Add people") give license number, resident state, license types
  ("Other" with text) and a license copy (PDF/JPG/PNG, 10 MB); NPN optional (7–10 digits). Employees: name and email
  only. Copies are stored encrypted and become License Documents pending review. Producers without a copy block
  payment (`AGENCY_REQUIRE_LICENSE_COPY`). The CSV template has license number and license type columns.
- properx.org intake: license number accepted without an NPN, license types with "Other". New
  `POST /api/intake/license-copy` (same HMAC) stores the application's license copy for the member, once per entry.
- Agency owner's welcome (the client's text) when the enrollment is paid, before the receipt; login link, reply-to
  and attachment from settings.
- Agency producers see their license read-only in the profile and upload new copies under License documents.
- Certificate: second signatory with a signature image (Anne Glorioso, Founder and CEO). The PDF cache key includes
  the image's contents.

## Migrations

1. `2026_11_20_100000_create_pending_license_files_table`
2. `2026_11_21_100000_add_license_document_to_intake_submissions`

Both additive.

## Environment (production values)

New:

| Variable | Value |
|---|---|
| `AGENCY_BILL_EMPLOYEES` | empty (= true) |
| `AGENCY_REQUIRE_LICENSE_COPY` | empty (= true) |
| `AGENCY_WELCOME_LOGIN_URL` | empty (the portal's sign-in page) |
| `AGENCY_WELCOME_REPLY_TO` | `hello@properx.org` |
| `AGENCY_WELCOME_ATTACHMENT` | empty for now (sent without it, logged); set later, when the client sends the file |

Changed:

| Variable | Value |
|---|---|
| `CERTIFICATE_SECOND_SIGNATORY_NAME` | `Anne Glorioso` |
| `CERTIFICATE_SECOND_SIGNATORY_TITLE` | `Founder and CEO` |
| `CERTIFICATE_SIGNATURE_IMAGE` | `signature-anne-glorioso.png` (in the release) |

`CERTIFICATE_SIGNATURE_IMAGE` now belongs to the second signatory and is used only with one; it was unset before.

## Routes and limits

- `POST /api/intake/license-copy`: machine route, `throttle:intake`, HMAC like the intake. JSON with the file in
  base64: up to about 13.4 MB. The ingress/WAF, nginx and PHP `post_max_size` must accept 15 MB on this path;
  otherwise the site gets 413 and retries (the member is created without the copy).
- No CSP or Permissions-Policy changes.

## Scheduler

`model:prune` (daily 01:30) also prunes `PendingLicenseFile` (copies of agency people never created, after 31 days,
with their files).

## Deploy

1. `php artisan migrate --force`
2. `php artisan optimize:clear`
3. `php artisan queue:restart`
4. `php artisan app:stripe:agency-prices`: all six prices OK (no new prices: partner rates use `price_data`).

## Tests

1109 tests pass; Pint clean.
