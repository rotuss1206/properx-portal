# Agency membership in ProPerx: requirements

English summary of the client spec "Членство агенцій у ProPerx · Вимоги для розробника та запит на
оцінку" (13 Oct 2026, Kevin / KEENEY). The original (Ukrainian, `docs/agency-membership-spec.docx`)
is kept locally and is not in git. Cite sections as "Agency spec §N". **We build Option B** (§12).

Contents: §1 Goal · §2 Current state · §3 Business rules · §4 Pricing and Stripe prices ·
§5 Enrollment flow · §6 Payment logic · §7 Data model and tagging · §8 Portal features ·
§9 Emails · §10 Edge cases · §11 Conflicts with the current implementation · §12 Option A and
Option B · §13 Estimate and questions · §14 Notes for this repository (not part of the client spec)

## §1 Goal

ProPerx Alliance wants agencies to enroll two or more members on one annual invoice. The client
asked for an hours estimate for two scopes (Option A, Option B) to add agency membership to the
existing Laravel portal. The developer sends:

- hours per area for Option A and Option B (table in §13);
- assumptions, and anything in the document they would do differently;
- answers to the questions in §13, plus any conflicts with the current implementation that were missed.

The WordPress marketing site (properx.org) and the individual application (Gravity Forms) are
KEENEY's and outside the estimate; they are described only where the portal depends on them.

## §2 Current state (as described by the client)

The portal foundation is done; licensing and payments are not. Reuse what exists wherever
possible and say so in the estimate.

| Area | Done | Not done yet |
|---|---|---|
| Infrastructure | Azure Blob storage with UUID file names and signed SAS URLs; Postmark with a basic webhook controller | Any Stripe integration |
| Security | TOTP 2FA with encrypted secrets and middleware; AuditRequestMiddleware logging key actions | Agency-level permissions |
| Admin (Nova) | Role-based access, Value/Trend metrics, CSV/Excel export, MemberProfile with colour-coded license status, PDF certificate button, read-only AuditLog with filters | Agency resources, license review queue |
| Data | 8 tables: users, member_profiles (with NPN fields), license_documents, tax_documents, library_documents, notification_settings, reminder_logs, audit_logs; seeded spatie roles (Executive Director, Membership Coordinator, Compliance Specialist, Member) | Agency tables; relationships still awaiting tests |
| API and public | Profile API v1, notification settings, certificate download, /health, public certificate verification page and endpoint with rate limiting | Agency endpoints |
| Automation | Job queue for PDF and email with retries, PDF cache clearing, daily `app:check-license-expirations` writing to reminder_logs | Invitation reminders, renewal jobs, dunning |
| Tests | API routes, verification logic, the expiry command | Everything agency-related |
| Licensing | NPN fields only | License portal unfinished; no NPN validation |

Individual members currently enroll through a Gravity Form with the Stripe add-on on the WordPress
site; that flow is not part of this estimate.

## §3 Business rules

Confirmed by the client; they override anything contradicting them in the client's original document.

1. **Annual dues only.** No monthly or semi-annual payment for anyone, individual or agency.
2. **No refunds.** No refunds, credits or prorated compensation in any situation, including agents removed mid-year.
3. **No enrollment deadline.** Health benefits are available at any time; no deadlines anywhere.
4. **Two kinds of people in an agency:** licensed agents (producers) and employees of a licensed agent. An employee can join only through an agency enrollment, never individually.
5. **License data is NPN and resident state only.** No license number, lines of authority, expiry date or license upload. Employees have no NPN.
6. **Minimum 2 people per agency**, the principal included.
7. **The tier follows the headcount.** Moving up immediately moves every person to the lower rate; dropping below a tier changes the rate at the next renewal, never mid-year.
8. **Founding rates are locked** for as long as the agency remains a member without a break. After a break, rejoining is at standard rates.
9. **Partner codes do not stack.** With a valid MGA/FMO code the rate is the lower of $149 and the tier rate.
10. **One annual agency invoice**, paid up front by card or ACH. A 2.9% surcharge applies to credit cards only (not debit, prepaid or ACH), as in the existing individual flow.
11. **The 24-month wait** before reapplying after cancellation still applies.
12. **The principal never sees** any person's health plan enrollment, plan choice or insurance claims.

## §4 Pricing and Stripe prices

Price is per person per year; the principal counts as one person. Create one Stripe product,
"ProPerx Agency Membership", with six annual recurring prices; the subscription quantity equals the headcount.

| Tier | Headcount | Stripe price (founding) | Founding rate | Stripe price (standard) | Standard rate |
|---|---|---|---|---|---|
| Agency | 2 to 9 | `agency_founding_2to9` | $159 | `agency_standard_2to9` | $179 |
| Agency Plus | 10 to 49 | `agency_founding_10to49` | $149 | `agency_standard_10to49` | $159 |
| Agency Enterprise | 50+ | `agency_founding_50plus` | $129 | `agency_standard_50plus` | $139 |

- **Founding cutoff:** founding prices for any payment completed before 12:00 a.m. Eastern on January 1, 2027. Store `rate_type = founding` on the agency and keep founding prices at every renewal while the subscription is active.
- **Rate formula:** rate = tier price for the headcount; with a valid partner code, rate = min($149, tier price). Annual total = rate × headcount, before any credit card surcharge.
- **Examples at founding rates:** 4 people = $636; 12 people = $1,788; 60 people = $7,740.

## §5 Enrollment flow

### §5.1 Overview

Six screens in a fixed order inside the portal, with a progress indicator: Membership type, Tier,
Agency, Principal, People, Review & payment. The "My agency" button on the marketing site leads to
Screen 1 (or to Screen 2 with the type preselected).

1. **Who is joining?** "Just me" returns to the individual Gravity Form on properx.org. "My agency (2 or more)" sets Member Type = Agency. Optional partner code field, filled from the partner QR link parameter.
2. **Choose a tier.** Three tier cards with founding or standard rates depending on the date. The choice is only a starting point; Screen 5 corrects it automatically. Note under the cards: "Not sure? Pick the closest one. We'll adjust it to the number of people you add."
3. **Your agency.** Fields in §5.2.
4. **Agency principal.** Fields in §5.3. The principal becomes the agency admin and counts as person 1.
5. **Add your people.** The principal is fixed as person 1 and cannot be removed. Rows are added one at a time; Option B adds CSV upload. Details in §5.4.
6. **Review & payment.** Summary, required consents, payment through Stripe. Details in §5.5.

### §5.2 Screen 3 · Agency

| Field | Required | Notes |
|---|---|---|
| Legal agency name | Yes | |
| DBA (trade name) | No | |
| Agency type | Yes | P&C (property and casualty), Health and Life, Multi-line, Other |
| Agency NPN | No | Only if the agency holds an entity license |
| FEIN | No | For invoices and receipts |
| Legal address | Yes | No PO boxes |
| Mailing address | No | "Same as legal address" checked by default |
| Main phone | Yes | |
| Website | No | |
| Billing email | Yes | Invoices and receipts |
| How did you hear about us? | No | Phone call, mailer, MGA/FMO partner, LinkedIn, Facebook, trade ad, referral, other |

### §5.3 Screen 4 · Principal

| Field | Required | Notes |
|---|---|---|
| First and last name | Yes | |
| Title | Yes | Owner, President, Principal, Managing Partner, Other |
| Email | Yes | Becomes the agency admin's login |
| Mobile phone | Yes | |
| SMS consent | No | Unchecked; a separate consent |
| Individual NPN | Yes | Numeric, 7 to 10 digits; format check only |
| Resident license state | Yes | Drop-down |
| Authority confirmation | Yes | "I am authorized to enroll this agency and its people in ProPerx Alliance and to pay membership dues on their behalf." |

### §5.4 Screen 5 · People

Per person:

| Field | Required | Notes |
|---|---|---|
| Person type | Yes | Licensed agent or Agency employee |
| First and last name | Yes | |
| Email | Yes | Unique across the portal; every person gets an invitation |
| Mobile phone | No | |
| Individual NPN | Agents only | Hidden for employees; unique across the portal |
| Resident license state | Agents only | Hidden for employees |

- **Running price block** (pinned right on desktop, bottom on mobile): headcount, tier, rate, annual total, e.g. "12 people · Agency Plus · $149 each · $1,788 a year".
- **Automatic tier:** crossing 10 or 50 switches the tier with a green note ("You've moved up to Agency Plus. Everyone now pays $149"); dropping below switches the tier down and says so.
- **Minimum:** with only the principal, "Continue" is disabled: "Agency membership needs at least 2 people. Add someone else or switch to individual membership", the last part linking to the individual form.
- **Partner code:** show "Partner rate applied" or "Your agency's rate is lower than the partner rate, so we applied it".
- **Duplicates:** an email or NPN already in the portal, or entered twice, is blocked with "Please contact ProPerx".
- **CSV upload (Option B):** a template with exactly the Screen 5 columns; rows with errors are shown for correction before continuing.

### §5.5 Screen 6 · Review & payment

- **Summary:** agency, principal, people with their type, tier, rate, annual total, and whether the rate is a (locked) founding rate.
- **Separate, unchecked, required checkboxes:**
  1. I agree to the Terms of Service and Privacy Policy.
  2. I understand that membership dues are separate from health plan premiums billed by the plan administrator, and that ProPerx does not sell insurance.
  3. I understand that membership is billed annually, dues are non-refundable, and a member whose membership is canceled cannot reapply for 24 months.
  4. I confirm that every person listed is either a licensed agent or an employee of this agency.
- **Payment** by card or ACH; the credit card surcharge is shown as a separate line before confirmation. Button: "Pay $X and enroll my agency".
- **Confirmation:** "Your agency is enrolled. We've sent everyone an invitation to set up their member account", with the roster, invitation statuses and a button to the dashboard.

### §5.6 Save and continue (Option B)

Saved after every screen; the principal gets an email with a link to continue.

## §6 Payment logic

- Stripe is new to the portal, so this is the first integration. Recommend an approach (e.g. Laravel Cashier or stripe-php directly) and state it in the estimate.
- **Checkout:** annual subscription, quantity = headcount, card or ACH Direct Debit.
- **Credit card surcharge:** 2.9% only when the PaymentMethod's `card.funding` is `credit`; debit, prepaid and `unknown` pay none. Shown as a separate line at checkout, on the receipt and on renewal invoices. Applied the same way to every payment flow.
- **Adding people mid-year** (Option B self-service; Option A staff): increase the quantity and charge the prorated amount immediately. If the headcount crosses 10 or 50, move every person to the lower tier price, prorated.
- **Removing people mid-year:** reduce the quantity at the next renewal, no refund or credit. If the headcount drops below the tier, move to the correct tier price at renewal.
- **Renewal:** keep founding prices while the subscription is active. Send a renewal notice 30 days ahead.
- **Failed renewal** (Option B automatic; Option A standard Stripe behaviour plus staff): 14 days of reminders, then every person in the agency becomes inactive and each is offered to continue individually.
- **Invoices:** one invoice to the billing email listing every person by name, with tier and rate.
- **Webhooks:** payment succeeded, payment failed, subscription updated, subscription deleted; all recorded in audit_logs.

## §7 Data model and tagging

Every person is a user with a member_profile linked to one agency. These fields serve reporting,
email campaigns and the call-centre CRM. The names are suggestions; adapt them to local conventions.

New table `agencies`:

| Field | Value |
|---|---|
| member_type | Agency |
| tier | agency_2to9, agency_10to49, agency_50plus |
| rate_type | founding, standard |
| partner_code | MGA/FMO code or null |
| headcount | Integer, updated automatically |
| principal_user_id | Agency admin |
| stripe_customer_id, stripe_subscription_id | Payment link |
| Agency details | The Screen 3 fields |

Additions to `member_profiles`:

| Field | Value |
|---|---|
| member_type | Individual, Agency Member |
| agency_id | Foreign key to agencies, nullable |
| agency_role | Principal, Member |
| person_type | Licensed producer, Agency employee |
| license_status | Pending review, Verified, Needs attention; not applicable to employees |
| invite_status | Invited, Account active |
| status | Active, Inactive (controls access when payment is overdue) |

**Roles:** add an Agency Principal permission set in spatie (dashboard and roster management for
their own agency only) on top of the Member role. Every agency action (enrollment, adding,
removing, reassignment, deactivation) is logged through the existing audit system.

## §8 Portal features

### §8.1 Agency dashboard (principal only)

- Roster with each person's type, invitation status and license status (both options).
- Adding people one at a time or by CSV, showing the prorated amount before confirming (Option B).
- Removing people, effective at the next renewal (Option B).
- Billing: tier, rate, renewal date, invoices and payment method update; the Stripe customer portal may be used (Option B).
- Editing the agency profile (Option B).

### §8.2 Invitations

- After payment every person gets, through Postmark, an invitation to set a password, turn on 2FA and complete their profile and consents.
- Reminders on day 3 and day 7 if the invitation is not accepted; the principal is copied on the second. Reuse the scheduler and reminder_logs pattern.

### §8.3 License review queue (Nova)

- Every new principal and agent enters as Pending review; employees are excluded.
- Staff check NPN and state in NIPR or the state registry, then mark Verified or Needs attention. Membership starts at payment either way.
- Filters by agency and status. On Needs attention the principal is emailed what to fix.
- The license portal of the original scope is unfinished: build the queue agency-aware from the start, and say which hours belong to this change and which to the original scope.

### §8.4 Staff tools (Nova)

- Agency resource with the roster, payment status and headcount.
- Principal reassignment (by staff only, never self-service).
- Manual adding and removing for Option A.

## §9 Emails

All sent through Postmark as queued jobs, honouring notification_settings where the category
allows. KEENEY provides the final texts.

| Trigger | Recipient | Content | Option |
|---|---|---|---|
| Payment completed | Principal | Welcome, receipt, roster, dashboard link | A and B |
| Payment completed | Each person | "[Agency] enrolled you in ProPerx Alliance" with an account setup link | A and B |
| Invitation not accepted, day 3 and day 7 | Person (principal copied on day 7) | Reminder | A and B |
| New agency enrolled | Anne and Bart | Agency, tier, headcount, amount, partner code | A and B |
| License marked Needs attention | Principal | What to fix and a link | A and B |
| Person added or removed | Principal | Updated roster and the prorated amount, if any | B |
| 30 days before renewal | Principal | Renewal date, headcount, tier, amount | A and B |
| Failed renewal and deactivation | Principal, then each person | Reminder, then an offer to continue individually | B |

## §10 Edge cases

In Option A staff handle all of these manually in Nova; in Option B the portal handles them as described.

| Situation | Handling |
|---|---|
| The person is already an individual member | Individuals enroll through WordPress, so there is no automatic matching. Staff decide manually in both options; no credit or refund. |
| The same person listed twice, or already in another agency | Blocked during entry with a message to contact ProPerx. |
| A person leaves the agency | The principal removes them, effective at renewal. The person gets an email offering individual membership. |
| The principal leaves or the agency changes owner | Staff reassign the principal role in Nova. |
| One person is left in the agency | At renewal, move the remaining person to individual membership. Employees cannot continue individually and are deactivated. |
| The agency stops paying | After a failed renewal and 14 days of reminders everyone becomes inactive; agents are offered individual membership. |

## §11 Conflicts with the current implementation

The new rules affect work already done. Confirm each item and include any rework in the estimate.

1. **License expiry tracking.** The client now collects only NPN and state, with no expiry date. `app:check-license-expirations`, LicenseExpiringNotification, reminder_logs entries and colour-coded license statuses all depend on the expiry date. Remove them, keep them for staff-entered dates, or switch to a status from NIPR? KEENEY will confirm with the client; estimate the removal option.
2. **license_documents table.** License uploads are no longer needed. Confirm whether to drop the table and its Nova screens or leave them unused.
3. **Employees without a license.** Certificates, verification and license status logic assume every member is a licensed agent. Employees need a separate certificate variant or none, and must be excluded from license checks.
4. **Role name.** The seeder uses "Compliance Specialist"; earlier requirements said "Compliance Officer". KEENEY will confirm which name stays.
5. **Model relationships** still await tests; agency relationships build on them, so include that testing if it is not planned yet.

## §12 Option A and Option B

Option A covers enrollment and payment, with later changes done by staff. Option B adds full
self-service for the principal. **This project implements Option B.**

| Capability | Option A | Option B |
|---|---|---|
| Six-screen enrollment, running price block, automatic tier, partner codes | Yes | Yes |
| Annual Stripe payment, card and ACH, credit card surcharge, founding rate lock | Yes | Yes |
| Invitations and reminders | Yes | Yes |
| Agency-aware license review queue | Yes | Yes |
| Read-only roster for the principal | Yes | Yes |
| Nova agency resource and principal reassignment | Yes | Yes |
| Roster CSV upload | No | Yes |
| Save and continue by email | No | Yes |
| Self-service adding and removing with proration | Staff in Nova | Yes |
| Billing section, invoices, payment method update | Staff | Yes |
| Automatic dunning and deactivation | Standard Stripe behaviour plus staff | Yes |
| Automatic edge case handling | Staff | Yes |

## §13 Estimate and questions

Hours per area for each option, excluding design, the WordPress site and KEENEY's project
management, including the developer's own testing:

| Area | Option A hours | Option B hours | Assumptions |
|---|---|---|---|
| Data model, migrations, roles and permissions | | | |
| Enrollment screens 1–6 | | | |
| CSV upload, save and continue | n/a | | |
| Stripe integration and payment logic | | | |
| Webhooks, renewals and dunning | | | |
| Agency dashboard | | | |
| Invitations and reminders | | | |
| License review queue (agency-related part only) | | | |
| Nova agency resources and staff tools | | | |
| Emails (templates from KEENEY's texts) | | | |
| Edge case handling | | | |
| Rework from the conflicts section | | | |
| Automated tests | | | |
| Deployment | | | |
| Total | | | |

Questions for the developer:

1. Laravel Cashier or stripe-php directly, and why?
2. What is the best base for the enrollment flow (Blade, Livewire, Inertia or something else), given the existing code?
3. Can the existing reminder_logs and scheduler patterns handle invitation reminders and renewal notices unchanged?
4. Should the existing Profile API v1 return agency data, or stay members-only?
5. Which part of the unfinished license portal work would you include in this change, and which would you finish in the original scope?
6. Are any of the rules above cheaper or safer to implement differently?

## §14 Notes for this repository (not part of the client spec)

- **Mail:** the client text says Postmark; the KEENEY platform allows only Azure Communication Services, which the portal uses (CLAUDE.md, "Mail").
- **Files:** the client text mentions signed SAS URLs; the portal never issues SAS or public URLs and streams blobs through short-lived signed routes (CLAUDE.md, "Downloads").
- **Option:** Option B (§12) is the agreed scope.
