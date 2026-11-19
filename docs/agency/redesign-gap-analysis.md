# Agency redesign: gap analysis

Date: 2026-11-18. Scope: every design in `docs/agency/screens/` (index: `logic/logic.jpg`) compared with the portal on `main`
(commit 4489d43) and with the local copy of properx.org. Read-only: no code, settings or Stripe objects were changed.

Client directive: one entry point, properx.org/apply, for individuals and agencies. Decisions already made are listed in
the brief and are taken as given here: individual checkout stays on the site (Gravity Forms + GF Stripe), "My agency"
hands off into the portal wizard at step 2, portal step 1 stays for direct links and QR codes, and the agency pricing
section is a new section on the site's Membership page.

Estimates are hours of development including tests, as a low–high range. They assume the current code base. Final
copy from KEENEY and the client is not included in the estimates.

## Summary

Most of the logic behind these designs already exists in the portal: the six-screen wizard with drafts and "finish
later", automatic tier by headcount with "moved up" notices, CSV import with per-row errors and partial import,
duplicate checks by email and NPN, the principal's authority checkbox, four review consents, card and ACH through
Stripe's Payment Element, the 2.9% surcharge for credit cards only, processing and failed states, the confirmation page
with everyone's invitation status, partner codes, the principal's agency page (people, add with proration, remove at
renewal, billing, agency details) and every email in the design except a "roster changed" note to the principal on a
removal.

Most of the work is therefore:

1. A new visual language for the wizard and the agency pages.
2. The site side: the "Who's joining?" form, the handoff, the individual payment changes and the pricing section.
3. A few new capabilities: .xlsx, partner codes per partner and for individuals, and live credit-card detection
   before "Pay".

Some designs conflict with rules the portal must keep. These need the client's decision before build:

- live license verification during enrollment;
- personal data in emails;
- removing the license expiration date;
- an employee as principal;
- surcharging every card.

**Total: 217–358 hours**, with options and exclusions listed in section 9.

---

## 1. Screens and states

Legend for "exists today?": **Yes** = same content and behaviour, restyle only; **Partly** = exists with differences
listed; **No** = new.

### 1.1 Wizard shell (all screens)

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| Header: logo, "Agency enrollment", "✓ Saved", "Finish later" | Partly | Our header is the logo and a page title; "Save and continue later" is a link at the bottom of the card. The design puts the save state and "Finish later" in a top bar on every screen from step 2. | "Saved" must reflect the last draft save (we save on Continue / Back / stepper; "Saved" must not show before the first save, because the draft is created on the first Continue). "Finish later" opens the existing continue-link panel. | `resources/views/join/layout.blade.php`, `livewire/agency-registration/wizard.blade.php`, `Wizard.php` | 3–5 |
| Stepper: six steps, green ticks for done, navy for current | Partly | Our stepper has six bars with numbers, done steps in navy-600; no ticks, no green. Mobile: design shows "Step N of 6 · Name" with one bar (we have the text, the bars stay). | none | `join/layout.blade.php`, `wizard.blade.php` | 2–4 |
| Visual language (cards, pill buttons, navy price card, type) | No | The designs use a new look: rounded pill buttons, navy primary, light blue page, a heavier geometric heading face. Fonts differ from the portal's Poppins / Open Sans; see question 1. | none | `join/layout.blade.php` (all styles of the wizard live there), `components/join/*` | 10–16 |
| "Welcome back, Dana. We saved your progress." (long roster screen) | No | Banner after opening a continue link. | Show once per continue link; the first name comes from the draft (no PHI in logs). | `ContinueEnrollmentController`, `wizard.blade.php` | 1–2 |
| **Subtotal** | | | | | **16–27** |

### 1.2 Screens 1–4

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| 1 · Who's joining? (desktop) | Partly | We show only "My agency" and a link "Apply as an individual on properx.org". The design has two equal cards, "Just me" and "My agency", and the partner code in its own card. | "Just me" must redirect to properx.org/apply (`INDIVIDUAL_MEMBERSHIP_URL`) instead of being a link. Partner code recognised on blur with the "whichever rate is lower" note (today it is checked only on Continue). | `steps/1.blade.php`, `MembershipForm.php`, `Wizard.php` | 4–7 |
| 1 · Mobile 390, partner code prefilled from QR link | Partly | "✓ Applied from your partner link…" message under the field. | QR links use `?partner=CODE` today; the design and brief use `?code=`. Accept both. | `Wizard.php` (mount), `steps/1.blade.php` | 1–2 |
| 2 · Choose your tier | Partly | Three cards with the founding price, "$179 from Jan 1 · Save 10%", "Selected" state, the "Not sure?" note. Ours is a radio list. | Tier choice and automatic adjustment on screen 5 already exist. Missing: the "Save N%" figure, whose basis is unclear (question 4). | `steps/2.blade.php`, `TierForm.php` | 3–5 |
| 3 · Your agency | Partly | The design shows legal name, DBA, business phone, billing email, business address and "Mailing address is the same". We also ask agency type (required), agency NPN, FEIN, website and "How did you hear". The design marks the list as "[Field list per developer requirements]". | If fields are dropped: agency type becomes optional and the Nova/agency page tolerates empty values. FEIN and agency NPN feed duplicate checks (blind indexes) and Stripe nothing; keep them optional (question 6). | `AgencyForm.php`, `steps/3.blade.php`, `Agency` Nova resource | 2–4 |
| 4 · Agency principal | Partly | The design drops "Title" and the SMS consent, and adds "Your person type: Licensed producer / Agency employee". The authority checkbox exists (`authority_confirmed`); final wording from KEENEY. | An employee as principal is refused today (`Agency::principalProblem()`: the principal must be a licensed producer with an NPN). Allowing it is an option (+6–10 h, not in the total; question 7). | `PrincipalForm.php`, `steps/4.blade.php` | 2–4 |
| **Subtotal** | | | | | **12–22** |

### 1.3 Screen 5 · Add your people

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| Populated, founding (12 people, moved up to Agency Plus) | Partly | Action bar (Add person, Upload a spreadsheet, Download the template), roster as a table with a person-type select in every row, NPN, state, licence status, delete icon. Ours is a list with Edit / Remove per person and the add form above it. | Changing the type in a row: producer → employee clears NPN and state; employee → producer opens the row's edit form (NPN and state are required). | `steps/5.blade.php`, `PeopleForm.php`, `Concerns/ManagesPeopleList.php` | 8–12 |
| Add a person (inline panel) | Yes | Restyle: type radio first, then names and email, then NPN and state. | none | `steps/5.blade.php` | 2–3 |
| "You've moved up to Agency Plus. Everyone now pays $149." / "You're now at Agency…" | Yes | Moves above the price card (ours is above the list). | none (`tierNotice`, `tierMovedUp`) | `steps/5.blade.php` | 1–2 |
| Price card (navy): people, tier, rate, annual total, founding note, "Billed annually…", Continue in the card | Partly | Our price card is white and Continue sits under the form. The design puts Continue in the card, disabled with a reason ("Add at least one more person", "Fix the highlighted rows"). | none | `price.blade.php`, `steps/5.blade.php` | 3–5 |
| Empty roster (principal only), Continue disabled | Yes | Restyle of the warning with "switch to individual membership". | none | `steps/5.blade.php` | 1–2 |
| Errors: upload rows, NPN format, duplicate member | Partly | Ours: "Rows to fix" with Fix / Skip per row; the design: one red panel "3 rows in whitfield-roster.xlsx need fixing. We added the 9 rows that were ready." with "Upload a corrected file", plus a red roster row "This person is already a ProPerX member. Contact ProPerX to add them." | Partial import already works the same way. Copy changes: the duplicate message (ours: "This email is already registered in the portal. Please contact ProPerX."), the NPN message including what was entered ("You entered 5"). | `PeopleForm.php`, `PeopleCsv.php`, `steps/5.blade.php` | 3–6 |
| Licence status column: Verified / Verifying / Needs attention / Not applicable | No | New column. | **Conflict:** live licence verification during enrollment needs a third-party licence API, which is out of scope. We check NPN format and duplicates only; staff verify after enrollment (`pending_review`). Proposal: "Not applicable" for employees, "Checked after enrollment" for producers (question 3). | `steps/5.blade.php` | 1–2 |
| Long roster (52), Enterprise, standard rate, partner code | Partly | Long table; the price card text "Partner code OHAA-2026 entered. Your agency rate is lower than the partner rate, so we applied it." | Livewire payload grows with the roster (up to 500): render the table without per-row components and keep row data out of public properties where possible. | `steps/5.blade.php`, `Wizard.php` | 2–4 |
| Partner rate applied (code in the price card) | Partly | "Partner rate applied (code MIDWESTFMO)." line in the card. | Logic exists (`partnerRateApplied`); see 2.1 for per-partner rates. | `price.blade.php` | 1–2 |
| Mobile 390: price bar pinned to the bottom | No | Fixed bottom bar: tier notice, "12 people · Agency Plus · $149 each", total, founding line, Continue; roster as cards. | Bar must not cover the last row or the on-screen keyboard; respects `env(safe-area-inset-bottom)`. | `join/layout.blade.php`, `steps/5.blade.php` | 4–6 |
| **Subtotal** | | | | | **26–44** |

### 1.4 Screen 6 · Review and pay

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| Review and pay, credit card, surcharge shown | Partly | Ours has a summary per screen with "Edit" links. The design has one "Summary" card (agency, principal, people by type, tier) without Edit. The price card becomes "Due today" with dues, surcharge and total. | none | `steps/6.blade.php`, `price.blade.php` | 4–6 |
| Four confirmations | Partly | Wording differs. The design: annual + non-refundable; 24 months; PPO access without a coverage guarantee; authorised + Terms and Privacy. Ours: Terms; dues separate; annual + 24 months; every person is an agent or employee. | New wording → `ReviewForm::CONSENTS`, `CONSENT_VERSION` 2 (drafts with version 1 ask again). Final wording from KEENEY. | `ReviewForm.php`, `steps/6.blade.php` | 1–2 |
| Card / Bank account (ACH) choice with the processor's fields below | Partly | The design has two radio cards above the fields. Ours uses the Payment Element's own tabs. | Drive the Payment Element by the choice (`paymentMethodTypes: ['card']` or `['us_bank_account']`, recreated on change). | `steps/6.blade.php`, `public/portal/join-payment.js` | 2–4 |
| "We detected a credit card, so a 2.9% surcharge is added" (live) | Partly | The design shows the surcharge as soon as the card is complete. Ours shows it after "Review payment" (a confirmation token and the server's check). | On the element's `change` event with `complete: true`, create the confirmation token in the background, call `preparePayment`, update the price card. The server still recomputes at "Pay". | `join-payment.js`, `Wizard.php` | 4–6 |
| One "Pay $X and enroll my agency" button | Partly | Ours: "Review payment", then "Pay". | Merge into one button. If the funding check changes the total, show the new total and ask again (never charge an amount the person has not seen). | `join-payment.js`, `Wizard.php`, `steps/6.blade.php` | 3–5 |
| Bank (ACH), no surcharge | Yes | Restyle; "[Processor's ACH debit authorization text]" is Stripe's mandate text, which the Payment Element shows itself. | none | `steps/6.blade.php` | included above |
| Processing | Yes | Spinner in the button, "This can take a few seconds. Please don't close this page." | none (ACH "processing" page exists too) | `steps/6.blade.php`, `processing.blade.php` | 1–2 |
| Payment failed | Partly | Red banner above the summary: "Your payment didn't go through. [Processor message]… Your roster is saved." | We show our own message per decline code (`CheckoutException::forCode`) and never the browser's or Stripe's raw text. Keep that, in the design's banner. | `steps/6.blade.php` | 1–2 |
| Mobile 390 | Partly | Stacked: summary, confirmations, payment, then "Due today" and the Pay button at the end. | none | `join/layout.blade.php` | 2–3 |
| **Subtotal** | | | | | **18–30** |

### 1.5 Confirmation

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| Agency enrolled, invites sent (desktop) | Partly | Ours shows the people with their invitation status, the receipt and "What happens next". The design shows a headline, four facts (agency, tier and people, "Paid today $1,839.85 · Visa ending 4242", "Renews [date] · founding rate"), "Go to agency dashboard", "A receipt is on its way to …", and the invitation list with badges. | The card brand and last four from the PaymentIntent's payment method (we already read it for the surcharge). The design shows the principal as "Account active", but the principal has not set a password yet: show "Check your email to set up your account" (question 13). "Go to agency dashboard" leads to sign-in until then. | `enrolled.blade.php`, `AgencyEnrollment.php` (receipt data) | 5–8 |
| Mobile 390 | Partly | Stacked facts, badge under each person. | none | `enrolled.blade.php` | 1–2 |
| **Subtotal** | | | | | **6–10** |

### 1.6 Agency dashboard (principal)

The design draws a separate shell: navy sidebar "My agency" with "People" and "Billing and profile", a header with
the agency name and an "Agency · Founding rate" badge. Today the principal uses the member area (`/member/agency`,
`/member/billing`) with the member menu, in the look of the member-area mockups (`docs/design`). This report assumes
we keep the member-area shell and bring the content and wording in line (option A). A separate shell for principals
is option B (+10–16 h; question 2).

| Screen / state | Exists today? | UI gaps | Logic gaps | Files affected | Estimate (h) |
|---|---|---|---|---|---|
| Shell and header | Partly | Agency name and rate badge in the header; menu labels "People", "Billing and profile". | none | `x-member.layout`, `App\Member\MemberMenu`, `App\Support\PortalHeader` | 2–4 |
| Roster | Yes | Columns match (name and email, type, invite status, licence status, Remove). Ours has more: chips by status, search, account status, "Resend invitation". The design's "Verifying" = our "Pending review" (wording only). | none | `member/agency/*.blade.php`, `App\Member\AgencyPage` | 2–4 |
| Add people: "Confirm and pay" modal, prorated charge, tier change | Partly | Ours is a page (`/member/agency/people/add`) with "Review the cost" and Stripe's proration lines. The design is a modal with one line, "1 person × $149, prorated for 6 of 12 months", plus the surcharge and "Charged today to Visa ending 4242". | Stripe's preview also carries credits when everyone's rate drops to a higher tier; one summary line must still add up to Stripe's total. Show "Includes the new rate for everyone" when there are credits (question 9). | `App\Livewire\Member\AddPeople`, views | 7–13 |
| Remove person: effective at renewal, no refund | Partly | Ours asks for a reason from a fixed list; the design has none. Text: "There's no refund for the time remaining." and "Your agency will have 8 people at renewal and stay at Agency." | The headcount and tier at renewal exist (`continuingHeadcount`, `AgencyTier::forHeadcount`). Whether the reason stays is question 10. | `member/agency/*`, `AgencyController` | 2–3 |
| Billing panel and invoices | Partly | On `/member/billing` today: plan, payment method, "Manage payment method" (Stripe portal), the last 12 invoices. The design puts billing and the agency profile on one page. | none | `BillingController`, `App\Member\BillingPage`, views | 4–6 |
| Agency profile edit | Yes | Ours has more fields (agency type, NPN, FEIN read-only, website) and split address fields. The design: legal name, phone, billing email, business address, "Mailing address is the same". | none (Stripe customer sync on a changed billing email or name exists) | `member/agency/*`, `AgencyDetailsRules` | 1–2 |
| Mobile: modals full screen | Partly | Our dialogs are not designed for 390 px. | none | `public/portal/member.css` | 2–3 |
| **Subtotal (option A)** | | | | | **20–35** |

### 1.7 Emails

See section 3. **Subtotal: 22–37.**

### 1.8 Site pricing section

See section 6. **Subtotal: 13–20.**

### 1.9 /apply form

See sections 4 and 5. **Subtotal: 17–27** (form and intake mapping), **18–27** (handoff), **6–10** (individual
payment, option A).

### 1.10 Mobile

See section 7. Most mobile work is counted in the rows above; the extra QA pass is **5–8**.

---

## 2. New concepts

### 2.1 Partner codes

**Today:**

- Data: `partner_codes` (code, partner name, active, expiry). Nova resource `PartnerCode`.
- Rate: one partner rate for all codes (`agency.partner_rate`, $149). An agency pays the lower of the partner rate and
  its tier rate.
- QR links: `/join/agency?partner=CODE`. The Stripe subscription uses `price_data` at the partner rate.
- Not on the site at all.

**Design:**

- Entry: a partner code field on /apply (both choices) and on wizard step 1. The QR link prefills it (`?code=`).
- Messages: "Partner code recognized. Your partner rate is applied on the next step." The price card names the code
  and says which rate applied.
- Rule: "agency and partner pricing can't be combined; the lower rate applies" (the rule we already implement).

| Part | Work | Estimate (h) |
|---|---|---|
| Data model | Optional per-code rate (`rate_cents`, null = config default), `applies_to` (agency / individual / both); migration, Nova fields; no change for existing codes. | 3–5 |
| Portal | `?code=` alias; live check on blur; the quote uses the code's own rate; the price card wording for both outcomes. | 2–3 |
| Site and portal endpoint | `POST /api/intake/partner-code` (HMAC as the intake, rate-limited, answers `valid` / `invalid` and, for individuals, the rate); plugin AJAX check on the site with the same message; the code passes through the handoff. | 4–6 |
| Individuals on the site (only if the client wants it) | Change the product price in Gravity Forms server-side (`gform_product_info`, `gform_pre_render`) after the portal confirms the code; the Stripe feed then charges the form total. The code goes to the portal in the intake for reporting. | 6–10 |
| Tests | Portal feature tests, plugin unit tests. | 3–5 |
| **Total** | | **18–29** |

Stripe: no new prices. Agencies already use `price_data` at the partner rate. Individuals pay the form total through
the GF Stripe feed.

**Needed from the client:**

- the rate per partner, or one rate for everyone;
- who issues codes and how partners receive them (QR, print);
- whether codes expire, and whether they have usage limits;
- whether codes apply to individual members, and at which rate;
- whether they want reporting per partner.

### 2.2 "Choose tier" versus automatic tier

Already implemented as the design describes:

- Screen 2 asks for a starting tier ("Not sure? Pick the closest one").
- Screen 5 switches the tier with the headcount and shows "moved up" / "now at" notices.
- The price is quoted for the tier's minimum headcount until people are added.

Work is visual only (counted in 1.2 and 1.3).

The open point is the agency dashboard. Adding people mid-year that reaches a higher tier already gives everyone the
new rate from now (prorated, `always_invoice`). The design says "[How the new rate applies before renewal: per billing
rules]". The project notes still mark mid-year moves as waiting for the client (question 9).

### 2.3 .xlsx template and upload

**Today:**

- A CSV template (UTF-8 with BOM, two sample rows).
- The browser reads the CSV and sends the text to Livewire. No file is stored.
- Each row is checked with the add form's rules; duplicates are skipped.
- Bad rows wait in "Rows to fix" with the spreadsheet row and message; the rest are added.

**Design:**

- "Download the template (.xlsx)"; upload of .xlsx or .csv.
- One error panel: "Row 4, Marcus Bell: …", "We added the 9 rows that were ready", "Upload a corrected file".

**Approach:**

- Generate the template server-side with OpenSpout (MIT, small, streaming; needs PHP `zip` and `xmlreader`; check
  that the KEENEY image has them).
- Read uploads in memory from a temporary file that is deleted at once, so no file is stored.
- Keep CSV as it is.
- Data validation lists in the template (type, state) need PhpSpreadsheet instead, which is much larger. Recommend
  OpenSpout with an "Instructions" sheet listing allowed values.
- New Composer dependency: needs the project owner's approval.

| Part | Estimate (h) |
|---|---|
| Template (.xlsx) with the same columns and two sample rows, an instructions sheet | 3–4 |
| Upload .xlsx (Livewire file upload, size and type checks, parse, delete), shared row checks | 4–7 |
| Error panel copy (row number, name, reason, "We added N rows") | 1–2 |
| Tests | 2–3 |
| **Total** | **10–16** |

### 2.4 Duplicate check ("This person is already a ProPerX member")

This exists. Email and NPN are checked against the portal (blind indexes) and within the enrollment, the principal
included. The 24-month rejoin rule is checked too.

Work: the design's wording, and showing the message on the person's row after a spreadsheet upload. An existing
individual member joins an agency only through staff ("Move to an agency" in Nova), which the message's "Contact
ProPerX to add them" matches.

Estimate: included in 1.3 (errors row).

### 2.5 Authorised officer, four agreements, processing, payment failed, confirmation

| Item | Status |
|---|---|
| Authorised officer checkbox | Exists on screen 4 (`authority_confirmed`). |
| Four agreements | Exist on screen 6, with different wording (consent version bump). |
| Processing (card and ACH) | Exists. |
| Payment failed | Exists. |
| Confirmation with invitations | Exists. |

Work: wording and layout only (1.2, 1.4, 1.5). Final wording from KEENEY.

### 2.6 Agency profile fields

Legal name, DBA, business phone, billing email, business and mailing address all exist (wizard screen 3, agency page,
Nova). Agency type, agency NPN, FEIN, website and the referral source are extra. Whether they stay is question 6;
dropping them is 2–4 h (counted in 1.2).

---

## 3. Emails

The design has one frame and eight content blocks:

- **Frame:** navy band with the white logo; heading, body, an optional grey data block, one yellow button; footer
  with the mailing address and why the email was sent.
- **Blocks:** heading, body, optional data block and button, as listed below.

Today every email uses the portal's mail layout: the colour logo on a light background, a white card and a footer.

| # | Block | Recipient | Today's class | Status | Estimate (h) |
|---|---|---|---|---|---|
| — | Template frame (header band, data block, button, footer with mailing address), CID white logo, Outlook and Gmail checks | — | `resources/views/vendor/mail/*`, `EmbedMailLogo` | change | 8–12 |
| 1 | Welcome and receipt, with roster | Principal | `AgencyWelcome` | exists (receipt, own set-up link). Roster table with names is new and conflicts with the no-PHI rule. | 2–3 |
| 2 | Agency invitation | Each person | `AgencyInvitationEmail` | exists; the design's subject and heading name the agency and the principal | 1–2 |
| 3 | Invite reminder, day 3 and day 7 (principal copied on day 7) | Person, principal | `AgencyInvitationEmail` (reminder), `Member\AgencyInvitationNotice` | exists | 1–2 |
| 4 | New agency enrolled | ProPerX staff | `AgencyEnrolledStaff` + Nova alert | exists; the design adds agency name, tier and headcount to the subject | 1–2 |
| 5 | License needs attention | Principal | `Member\LicenseNeedsAttention` | exists (the bell names the person, the email does not) | 1–2 |
| 6 | Person added or removed ("Your roster changed") | Principal | `AgencyPeopleAdded` (added) | **partly new:** a note to the principal on a scheduled removal does not exist (the removed person gets `AgencyRemovalScheduled`); one combined "roster changed" template | 3–5 |
| 7 | Renewal in 30 days | Principal | `Member\RenewalReminder` | exists at 14, 7 and 1 days; 30 is an allowed value (`AGENCY_RENEWAL_REMINDER_DAYS=30` or `30,14,7,1`). **Conflict:** design 30 days, we send 14/7/1 (question 11). | 1–2 |
| 8 | Failed renewal, then deactivation, "Continue individually" | Principal, then each person | `AgencyPaymentFailed`, `Member\PaymentOverdueReminder`, `Member\MembershipExpired`, `Member\AgencyMembershipEnded` | exist; **new:** "Continue individually" button to properx.org/apply in the deactivation email to each person | 2–3 |
| — | Tests (`MailBrandingTest`, no-PHI checks) | | | | 2–4 |
| | **Total** | | | | **22–37** |

"New" emails in the brief, checked against the code:

- **License needs attention:** exists.
- **Roster changed:** only the removal note to the principal is new.
- **Renewal in 30 days:** a configuration change.
- **Failed renewal → deactivation with "Continue individually":** exists, without the button.

**Conflict with the no-PHI rule.** The design puts personal and agency data into subjects and bodies:

- "Your agency is enrolled, Dana."
- "[Principal name] added you…"
- "[Person name] was added to your roster"
- "We couldn't verify a license for [Person name]"
- a roster table with names.

The KEENEY rule for this platform is that email subjects and bodies carry no PHI. Names, NPNs and agency identifiers
stay in the portal, and the email only links back. The current emails follow that rule; `docs/agency-emails.md` says
so. We need KEENEY's answer before using names (question 12). Until then, each block keeps its structure and refers to
"a person on your roster", with the name shown after sign-in.

---

## 4. /apply handoff (site → portal)

### Proposed mechanism

1. On /apply, "My agency" shows the short block:
   - principal first and last name, email, phone;
   - agency name, approximate headcount, optional partner code.

   Gravity Forms conditional logic hides the individual fields and the payment. The Stripe feed and the User
   Registration feed get the condition "Who's joining? is Just me", so an agency submission never charges or creates a
   WordPress user.
2. The plugin hooks the form's submission (`gform_after_submission` for form 1 with "My agency"). It posts the block to
   a new portal endpoint, `POST /api/intake/agency-start`:
   - the same HMAC as `/api/intake/individual` (`X-Timestamp`, `X-Signature`, `WP_INTAKE_SECRET`), 5 minutes
     tolerance;
   - idempotent per entry in `intake_submissions` (source `properx.org/agency-start`).
3. The portal:
   - validates the fields with the wizard's own rules (principal email, US phone, partner code);
   - applies the duplicate and rejoin checks;
   - creates a wizard draft with step 1 done ("My agency" + partner code), the principal's name, email and phone on
     screen 4, the agency name on screen 3, and the tier from the headcount band; `furthestStep = 2`;
   - answers with a one-time URL `/join/agency/start/{token}`. Only the token's hash is stored, as for the continue
     link.
4. The plugin answers with a redirect confirmation (`gform_confirmation` returning `['redirect' => $url]`; this works
   for AJAX forms too).
5. `/join/agency/start/{token}` puts the draft in a regenerated session, spends the token and lands on step 2. It is
   the same controller pattern as `ContinueEnrollmentController`.

### Details

- **Expiry.**
  - The start token lives for 30 minutes, because it is followed at once.
  - The draft follows the existing rule: 30 days after the last save.
  - A used or expired start link shows the existing "link unavailable" page with "Start again at properx.org/apply".
- **An existing draft for the same email.** Never hand an existing draft to whoever types an email on the site: a
  draft can hold the agency's whole roster. Instead:
  - create a fresh draft;
  - if an unfinished draft exists for that email, send that address the existing continue link ("You started an
    enrollment earlier; here is your link").
  - This needs a blind index of the principal email on `agency_registration_drafts` (migration).
- **Partner code.**
  - Passed through and checked by the portal.
  - An invalid code does not block: the draft keeps step 1 open with the message.
  - Valid codes show "Partner code recognized" on the site first (2.1).
- **Email already belongs to a member.** The portal answers `outcome: existing_member` without creating anything. The
  site shows: "This email already has a ProPerX account. Sign in to the member portal, or contact ProPerX to enroll
  your agency." This tells the visitor that the email is a member. That is the same exposure as the wizard's current
  message; see question 14. Accounts blocked by the 24-month rule get `RejoinRule::MESSAGE`, without dates.
- **Portal not reachable.** The site keeps the entry, shows "We couldn't open the enrollment right now. We've emailed
  you a link to continue." and retries in the background, like the intake's retries. The link is then sent by the
  portal with `AgencyEnrollmentContinueLink`.
- **CSP and CORS.** No browser call crosses sites: the plugin calls the portal server to server, then the browser
  follows a top-level redirect. There is no CORS and no portal CSP change. The site needs no CSP change either. The
  intake rate limit is per IP (60/min) and all calls come from the site's server, so a separate limiter
  `agency-start` (for example 30/min) keeps the individual intake unaffected.
- **Privacy.** The Gravity Forms entry keeps the principal's details on the site, as the individual application does
  today. Optionally the plugin deletes the agency entry after a successful handoff (setting, off by default).

| Part | Work | Estimate (h) |
|---|---|---|
| Portal | Endpoint, validation, draft creation, token, landing route, email blind index on drafts, existing-member and rejoin outcomes, rate limiter, tests | 10–15 |
| Plugin | Hook, HMAC call, redirect confirmation, error path and retry, settings switch "Send agency enrollments to the portal" (off by default), "Test" in the checklist, unit tests, version 2.2.0 | 8–12 |
| **Total** | | **18–27** |

---

## 5. Individual "Just me" on the site

### Installed versions (local copy, read-only)

| Component | Version |
|---|---|
| WordPress | 7.1.4 |
| PHP | 8.4.13 |
| Gravity Forms | 3.2.3.1 |
| Gravity Forms Stripe Add-On | 7.0.0, checkout method `stripe_elements`, field 19 with `enableMultiplePaymentMethods` on (Stripe's Payment Element) |
| Gravity Forms User Registration Add-On | 5.5.2 |
| Gravity Forms Webhooks Add-On | 1.7.0 |
| Elementor / Elementor Pro | 4.3.6 / 4.3.3 |
| ProPerX Portal plugin | 2.1.0 |

Form 1 "Membership Application Form" is on page 26 (/apply). Stripe feed: subscription, yearly,
`recurringAmount = form_total`, no setup fee.

### What the current form already does

Several items in "Individual form: what changed" are already true on the local copy:

- Field 28 has one plan, "Individual Membership" with $199 struck through and $169/yr.
- Monthly, Semi-Annual and Founding cards are gone.

The surcharge today is user-declared:

- Field 29 is a radio: "Credit Card (3% surcharge applies)" / "Debit Card" / "ACH Direct Debit".
- Field 30 is a calculated product of 3% of the plan, shown when field 29 is "Credit Card".
- The surcharge is part of the form total, and therefore of every yearly renewal (audit, section 10, items 1–3).

### Can GF Stripe 7.0 do the design's payment?

| Requirement | Natively | With hooks / code | Notes |
|---|---|---|---|
| Annual only, $169 with $199 struck through | Yes | — | Already in place (field 28). The date switch on Jan 1, 2027 is manual admin work, or a small `gform_pre_render` filter (1–2 h). |
| Card + ACH (`us_bank_account`) through the Payment Element | Yes | — | Field 19 already uses the Payment Element with several methods. ACH needs `us_bank_account` enabled in the Stripe account and the GF Stripe webhook working (on the copy `webhooks_enabled` is empty; check production). |
| 2.9% surcharge on credit cards only, decided before payment | **No** | Not reliably | GF creates the subscription server-side from the form total before the browser confirms. Its Payment Element script exposes no hook to read the card's funding before submission (no `createConfirmationToken` step and no JS filter). The funding is known only after payment. |
| Surcharge charged once, not on every renewal | Yes | — | Use the feed's setup fee (a one-time amount on the first invoice) = the surcharge field; the recurring amount = the plan only. |

### Options

| Option | What it does | Admin (manual) | Code | Estimate (h) | Notes |
|---|---|---|---|---|---|
| **A. Declared, checked afterwards (recommended now)** | Radio "Credit card (2.9% surcharge) / Debit card / Bank account (ACH)"; surcharge 2.9% as the feed's setup fee; after payment the plugin reads the payment method's `card.funding` and, on a mismatch, records it on the entry and alerts staff (no automatic extra charge). | Field 29 labels, field 30 formula `* 0.029`, feed: setup fee = field 30, recurring = field 28 | mu-plugin or plugin: funding check on `gform_post_subscription_started`, entry note, staff email; replace the theme's `validate_stripe_card_surcharge` | 6–10 | Same experience as today; honest disclosure before paying; mismatches handled by staff. |
| B. Detect credit before paying | Our script on /apply reads the card's funding from Stripe before GF submits, sets the surcharge, and the server re-checks it. | as A | Depends on GF's internal Elements instance, or replaces its submit flow; must be re-checked on every GF Stripe update | 4 (spike) + 20–32 | Only if the spike shows a stable hook. Otherwise the realistic route is option E. |
| C. Surcharge on every card | Card → 2.9%, ACH → none. | labels, conditional on the Payment Element tab | small | 2–4 | **Not recommended:** card network rules and US federal rules do not allow surcharging debit or prepaid cards. |
| D. No surcharge | Remove fields 29 and 30. | remove fields | none | 1–2 | Simplest; the design's line disappears. |
| E. Our own Payment Element on the site | The plugin renders the payment step itself (as the portal's wizard does) and creates the Stripe subscription; GF keeps the fields and the entry. | feed off for form 1 | large: Stripe calls, webhooks, failure states, ACH processing, tests | 30–50 | Matches the design exactly; replaces what GF Stripe does today. Not in the total. |

The total counts option A (6–10 h).

### Exact form changes (form 1)

| Change | Field | Kind | Work |
|---|---|---|---|
| Add "Who's joining?" (Just me / My agency) at the top, with the design's two cards | new radio | Admin + CSS | admin |
| Agency block shown for "My agency": first and last name, email, phone, agency name, "About how many people?" (2–9 / 10–49 / 50+), "What happens next" with three tier prices | new fields + HTML | Admin; tier prices from the plugin's shortcode (section 6) | admin + code |
| "Continue to agency enrollment →" button text for "My agency" | submit button | Admin (conditional button text needs a small filter) | code |
| Partner code (optional) under the choice, with "Partner code recognized" | new text field | Admin + plugin AJAX check (2.1) | code |
| Conditional logic: every individual field and the payment only for "Just me" | all individual fields | Admin | admin |
| Stripe feed and User Registration feed: condition "Just me" | feeds | Admin | admin |
| Remove "Insurance license expiration date" | 15 | Admin | admin; **intake change required** (below) |
| Remove "License Type(s) Held" | 17 | Admin | admin; intake change |
| Resident license state: required, a plain state select instead of an address field | 16 → new select | Admin | admin; mapping change |
| Keep NPN | 14 | — | — |
| Annual only, $169, $199 struck through | 28 | Already done | — |
| Card / ACH choice and surcharge line | 29, 30, 19 | Admin (option A) | admin + code (option A) |
| Order summary box ("Individual membership, 1 year", surcharge, "Total today", note on debit and bank) | HTML + Total field | Admin + CSS | admin |
| Three required agreements, reworded: (1) annual, non-refundable, 24 months; (2) renews each year unless canceled; (3) Terms and Privacy | 24, 32, 25 | Admin (also fixes the duplicate label of 24 and 32) | admin |
| Remove the 3-month minimum and refund wording | HTML 18, 21, confirmation text | Admin | admin |
| "How did you hear about ProPerX Alliance?" kept | 27 | — | — |
| Visible card field labels | 19 | Already: the Payment Element shows labels above its fields | — |
| Confirmation for "Just me": no "review in 3–5 days" | confirmation | Admin | admin |

Admin work is about 6–10 h. Every change is mirrored on production by hand, so each needs click-path steps in
`docs/wordpress/changes.md` (3–5 h).

### Impact on the intake mapping

- **Portal:** `/api/intake/individual` requires `license_expires_at` (`IntakeController`: `required|date_format`).
  Removing field 15 would refuse every application. It must become optional.
  - `IndividualIntake` already copes with an empty `license_type`.
  - **Product impact:** new individual members then have no licence expiry date, so the 60/30/7-day licence
    reminders, the licence status and the certificate's licence-based checks do not apply to them until a licence
    document with an expiry is uploaded or staff enter the date (question 5).
- **Plugin:** `connector.php` maps `license_expires_at => '15'` and `license_types => 17.1–17.5`, and reads the state
  from 16.4. These must follow the new field IDs. The settings page and the Setup checklist must also stop expecting
  15 and 17.
- **Docs:** `docs/wordpress/audit.md` (field table and mapping, section 9), `docs/wordpress/deploy.md`,
  `INSTALL-site.md` and `changes.md` need updates.

| Part | Estimate (h) |
|---|---|
| Admin changes on the copy + click-path steps in `changes.md` | 9–15 |
| Portal intake: optional expiry, state code from the new field, tests | 3–5 |
| Plugin mapping, settings, checklist, tests | 3–4 |
| Docs (`docs/wordpress/*`) | 2–3 |
| **Total** | **17–27** |

---

## 6. Site pricing section

Approach: a shortcode from our plugin, `[properx_pricing]`, placed in an Elementor Shortcode widget on the Membership
page (/membership, page 20).

- It renders the individual strip and the three agency cards with "Enroll my agency" buttons, in the design's layout,
  responsive down to 390 px.
- It switches automatically at 2027-01-01 00:00 America/New_York:
  - before: founding prices, "$179 from Jan 1" and the "Save N%" badges;
  - after: standard prices only, no badges, no founding paragraph.
- Prices and the cutoff come from one source: the portal's `config/agency.php`.
  - The portal gets a small public, cached endpoint `GET /api/pricing` (no personal data, rate-limited).
  - The plugin caches the answer for an hour and falls back to the last known values. The site and the portal can
    then never show different prices.
  - Without the endpoint, the prices live in the plugin's settings (a drift risk).
- "Enroll my agency" links to `/apply?join=agency`. Gravity Forms' dynamic population preselects "My agency" (parameter
  name on field "Who's joining?").
- Elementor page caching: the shortcode output must not be cached past the cutoff. Use a short cache, or vary by date.

| Part | Estimate (h) |
|---|---|
| Shortcode, markup and CSS (desktop and mobile), date switch | 8–12 |
| Portal pricing endpoint + plugin cache and fallback | 3–5 |
| Elementor placement, dynamic population of the form, `changes.md` steps | 2–3 |
| **Total** | **13–20** |

---

## 7. Mobile 390

| Screen | Work | Counted in |
|---|---|---|
| Wizard header | Logo, "Agency enrollment", "Saved / Finish later" on a second line | 1.1 |
| Stepper | "Step N of 6 · Name" with one progress bar | 1.1 |
| Screen 1 | Cards stacked, partner code card, prefilled message | 1.2 |
| Screen 5 | Roster as cards (name, email, type select, NPN · state, status, delete); price bar pinned to the bottom with the tier notice and Continue | 1.3 |
| Screen 6 | Stacked: summary, confirmations, payment, then "Due today" and Pay at the end (not pinned) | 1.4 |
| Confirmation | Facts stacked; each person's badge under the name | 1.5 |
| Agency dashboard | Modals full screen; the roster already has a mobile layout | 1.6 |
| Pricing section | Cards stacked, individual strip with the button under the price | 6 |
| /apply | Two-column field rows become one; the choice cards stacked | 5 |
| QA pass at 390 / 1100 / 1440 with classic scrollbars, screenshots against the designs (project rule) | | 5–8 |

---

## 8. Questions for the client

1. **Look.** The new agency designs use a different look from the member-area designs the portal follows (fonts,
   buttons, colours). Should the wizard and the agency pages follow the new look, and should the member area change to
   match?
2. **Agency dashboard.** Keep the principal inside the member area (with "My agency" and "Billing"), or give
   principals the separate two-item layout from the design?
3. **License status during enrollment.** The design shows "Verified / Verifying" while people are added. We cannot
   verify licenses live (no license-verification service is in scope); ProPerX staff verify after enrollment. Is
   "Checked after enrollment" acceptable on screen 5?
4. **"Save 10% / 20% / 30%".** Compared with what? ($159 vs the $179 standard rate is 11%; vs the $199 individual
   price it is 20%.)
5. **License expiration date.** If the individual form no longer asks for it, license expiry reminders (60/30/7 days)
   cannot work for new individual members until they upload a license. Is that intended, or should we keep the field?
6. **Agency details.** May we keep agency type, agency NPN, FEIN and website on step 3 (optional), or remove them?
7. **Principal.** Can an agency employee (not a licensed producer) be the principal? Today the principal must be a
   licensed producer with an NPN.
8. **Partner codes.** One partner rate for everyone, or a rate per partner? Who issues codes? Do they expire? Do they
   apply to individual members, and at what price?
9. **Moving up a tier mid-year.** When an agency adds people and reaches a higher tier, should everyone's lower rate
   start now (prorated, as built) or at renewal?
10. **Removing a person.** Should the principal still pick a reason (left the agency, role changed, added by
    mistake), or not?
11. **Renewal reminder.** One reminder at 30 days (design), or 14, 7 and 1 days before (current), or both?
12. **Names in emails.** The designs put names and the agency name in subjects and bodies. The platform rule is that
    emails carry no personal data and only link to the portal. Can KEENEY confirm whether names are allowed?
13. **Confirmation page.** The principal has not set a password at that point. Is "Check your email to set up your
    account" acceptable instead of "Account active"?
14. **Existing members on /apply.** If someone enters an email that already has an account, may the site say so
    ("This email already has a ProPerX account")?
15. **Credit card surcharge on the site.** Gravity Forms cannot detect a credit card before payment. Is a declared
    choice ("Credit card / Debit card / Bank account") with a check after payment acceptable? Debit cards cannot be
    surcharged.
16. **Surcharge on renewals.** Should the 2.9% apply only to the first payment, or to every yearly renewal by credit
    card?
17. **Final wording.** We need the final text of the four agency agreements, the three individual agreements, the
    authorised-officer statement and the email copy.
18. **Mailing address.** Which mailing address goes in the email footer?

---

## 9. Totals and build order

### Totals

| Area | Low | High |
|---|---:|---:|
| Wizard shell | 16 | 27 |
| Screens 1–4 | 12 | 22 |
| Screen 5 | 26 | 44 |
| Screen 6 | 18 | 30 |
| Confirmation | 6 | 10 |
| Agency dashboard (option A) | 20 | 35 |
| Emails | 22 | 37 |
| Partner codes | 18 | 29 |
| .xlsx template and upload | 10 | 16 |
| /apply handoff (portal + plugin) | 18 | 27 |
| /apply form and intake mapping | 17 | 27 |
| Individual payment on the site (option A) | 6 | 10 |
| Site pricing section | 13 | 20 |
| Mobile QA pass | 5 | 8 |
| End-to-end checks in the Stripe sandbox, screenshots against the designs | 10 | 16 |
| **Total** | **217** | **358** |

Options and exclusions:

- **Not in the total:**
  - an employee as principal: +6–10;
  - a separate dashboard shell (option B): +10–16;
  - per-person names in emails if approved: +2–4.
- **Replaces option A if chosen:**
  - credit detection before payment on the site (option B): 4 spike + 20–32;
  - the site's own Payment Element (option E): 30–50.
- **Excluded:** live license verification through a third-party service (out of scope).

### Build order

Each phase can ship and be tested on its own.

1. **Quick wins, no new look (8–14 h).**
   - Consent wording v2 (once final).
   - `?code=` alias.
   - Duplicate and NPN messages.
   - Optional `license_expires_at` in the intake.
   - The "Continue individually" button.
   - Renewal reminder days per the client's answer.
   - The removal note to the principal.
2. **Site handoff (35–54 h).**
   - Portal endpoint and landing.
   - Plugin hook.
   - "Who's joining?" and the agency block on /apply.
   - Feed conditions and the intake mapping.
   - Tested end to end on the local copy with "My agency" landing on step 2.
3. **Individual payment on the site (6–10 h, option A).** Surcharge as a one-time setup fee, the funding check after
   payment, the reworded agreements, removed fields. Tested with card, debit and ACH in Stripe test mode.
4. **Wizard redesign (78–133 h).** Shell, screens 1–6, confirmation, mobile, the single Pay button and live credit
   detection. Behind the existing `AGENCY_ENROLLMENT_ENABLED` switch.
5. **Partner codes extension (18–29 h).** Per-code rates, the site check, individuals if approved.
6. **.xlsx (10–16 h).** Template and upload alongside CSV.
7. **Agency pages (20–35 h).** Roster, add-people confirmation, remove dialog, billing and profile.
8. **Emails (22–37 h).** New frame first (all emails change look at once), then the blocks.
9. **Pricing section (13–20 h).** Shortcode, endpoint, placement on /membership. Can ship any time after phase 2;
   before Jan 1, 2027 if the founding prices should show automatically.

The QA pass and the sandbox checks (15–24 h) are spread across the phases. Phase 1's items are already counted in the
areas above (screens, emails, intake), so the phases add up to slightly more than the total.
