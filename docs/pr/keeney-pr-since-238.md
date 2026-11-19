# Agency membership, renewals, staff Nova, certificate designs, properx.org single entry

## Summary

- Agency enrollment at `/join/agency` (off until `AGENCY_ENROLLMENT_ENABLED`): six steps, people one by one or by CSV,
  card or bank payment with Stripe's Payment Element, 2.9% surcharge on credit cards only, save and continue later.
- Agency lifecycle: invitations with day 3/7 reminders, renewal reminders, grace period and expiry, cancellation
  (agencies and paid members are never deleted), adding people with a prorated charge, removal at renewal, staff move
  an individual member into an agency.
- Principals get "My agency"; members get "Billing" (plan, invoices, Stripe Customer Portal for the payment method).
- Staff: license verification queue, alerts in the bell by role, optional morning summary, Nova in the portal's look;
  Nova is closed to members while the member area is on.
- Certificate: live preview and PDF in the new designs, a fixed date of issue, PNG download.
- properx.org: canceled individual subscriptions start the 24-month rejoin wait; "My agency" on /apply opens a
  prefilled enrollment (off until `AGENCY_HANDOFF_ENABLED`); the partner code is kept for reporting.
- License documents: earlier uploads on the upload page, review decisions in the bell and by email.

## Migrations (run order)

1. `2026_11_11_100000_add_subscription_state_to_agencies_table`
2. `2026_11_11_110000_create_agency_invitations_table`
3. `2026_11_12_100000_add_license_verification_details_to_member_profiles_table`
4. `2026_11_12_100100_seed_license_verification_permission`
5. `2026_11_13_100000_seed_agency_cancel_permission`
6. `2026_11_13_110000_add_renewal_tracking`
7. `2026_11_13_120000_create_app_markers_table`
8. `2026_11_13_130000_add_scheduled_removal_to_member_profiles_table`
9. `2026_11_17_100000_add_rejoin_rule_to_member_profiles_table`
10. `2026_11_18_100000_add_handoff_to_agency_registration_drafts`
11. `2026_11_18_100000_create_certificate_issues_table`

All additive. 10 and 11 share a timestamp; they run in this (file name) order and do not depend on each other.

## Environment (production values)

New:

| Variable | Value |
|---|---|
| `AGENCY_HANDOFF_ENABLED` | `false` |
| `MEMBERSHIP_EXPIRY_EMAILS_ENABLED` | `false` |
| `RENEWAL_REMINDERS_INDIVIDUAL_ENABLED` | `false` |
| `STAFF_SUMMARY_ENABLED` | `false` |
| `MEMBERSHIP_EXPIRY_EMAILS_SINCE` | empty |
| `MEMBERSHIP_GRACE_DAYS` | `14` |
| `MEMBERSHIP_GRACE_REMINDER_DAYS` | `7,1` |
| `MEMBERSHIP_REJOIN_WAIT_MONTHS` | `24` |
| `AGENCY_RENEWAL_REMINDER_DAYS` | `14,7,1` |
| `AGENCY_STAFF_NOTIFICATION_EMAIL` | empty (or the staff mailbox) |
| `AGENCY_SURCHARGE_EXEMPT_STATES` | empty |
| `PORTAL_TERMS_URL`, `PORTAL_PRIVACY_URL` | empty (properx.org's pages) |
| `STRIPE_KEY` | Stripe live publishable key (`pk_live_...`); only used once enrollment is on |
| `STRIPE_PORTAL_CONFIGURATION` | empty (the account's default) |

Changed:

| Variable | Value |
|---|---|
| `INDIVIDUAL_MEMBERSHIP_URL` | `https://properx.org/apply/` |

Unchanged, stay off: `AGENCY_ENROLLMENT_ENABLED=false`, `INTAKE_SEND_INVITE=false`.

Removed: `PAYMENT_GRACE_DAYS` (still read only when `MEMBERSHIP_GRACE_DAYS` is unset).

## Scheduler

- Removed: `app:send-renewal-notices`.
- Added (all `onOneServer`, `withoutOverlapping`, portal time zone):
  - `app:send-renewal-reminders`, hourly 07:00–09:00
  - `app:expire-memberships`, hourly at :20
  - `app:apply-agency-removals`, hourly at :25
  - `app:send-invitation-reminders`, hourly 09:00–11:00
  - `app:send-staff-summary`, daily 08:00 (does nothing while `STAFF_SUMMARY_ENABLED` is off)

## CSP and Permissions-Policy

- `/join/agency` only:
  - CSP adds Stripe's sources to `script-src`, `connect-src` and `frame-src`.
  - `Permissions-Policy` becomes `payment=(self "https://js.stripe.com")`.
- `/member/billing` only: `form-action` adds `https://billing.stripe.com`.
- All other pages: unchanged.

## Deploy

1. `php artisan migrate --force`
2. `php artisan optimize:clear` (or `config:clear` and the platform's cache rebuild)
3. `php artisan queue:restart` (restart the queue workers)
4. Only when agency enrollment is turned on: `php artisan app:stripe:agency-prices` must report all six prices.

## Known issue

Trivy flags DOMPurify (GHSA-cg2g-xh64-v4q7, critical) bundled in Laravel Nova 5.11. Nova 5.11.1 still ships an
affected version (3.4.16; fixed in 3.4.17), and there is no fixed Nova release yet. Nova is upgraded as soon as one is
out.
