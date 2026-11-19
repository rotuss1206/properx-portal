# WordPress changes log

Every change made to the local copy of properx.org (`D:\WORK\OSPanel6\home\properx-org.local`, https://properx-org.local).

- **Mirror on production** = repeated on properx.org by hand, using the steps below. They are also in [INSTALL-site.md](INSTALL-site.md).
- **Local only** = must never be repeated on production.

No secret values are written here.

## Mirror on production

| # | Date | Change | Steps |
|---|---|---|---|
| 1 | 2026-11-05 | ProPerX Portal plugin | Plugins > Add New > Upload Plugin > `properx-portal-<version>.zip` (built by `scripts/build-wp-package.ps1`) > Install Now > Activate Plugin. Replaces the earlier must-use connector, which never went to production. |
| 2 | 2026-11-05 | Plugin settings | Forms > Settings > ProPerX Portal: Portal URL, Form ID, then Kevin pastes the Secret; Save settings; Test connection; tick "Send paid applications to the portal" when the checklist allows; Save settings. "Approval email" stays off until the client approves the text. Production's own values; nothing is copied from here. |
| 3 | 2026-11-05 | "User Pending Confirmation" sent after payment, not on submission | Forms > Membership Application Form > Settings > Notifications > User Pending Confirmation > Event: **Subscription Created** > Update Notification. Do this after step 1, with the Form ID set: Stripe can report a new subscription twice at the same moment, and the plugin makes this email go once per entry. |
| 4 | 2026-11-05 | Resident License State and License Type(s) Held required | Forms > Membership Application Form > click **Resident License State** > General > tick Required. The same for **License Type(s) Held**. Save Form. |
| 5 | 2026-11-05 | /apply-test unpublished | Pages > All Pages > Apply Test > Quick Edit > Status: Draft > Update. |
| 6 | 2026-11-05 | WP Dummy Content Generator off | Plugins > Installed Plugins > WP Dummy Content Generator > Deactivate. |
| 7 | 2026-11-09 | Plans page (ID 919): "How to enroll" and the enrollment link for Active Members only; a call to apply for visitors; "under review" for Pending Members | Steps in "Item 7" below. Then install plugin 2.0.6 or later, whose approval email names the "How to enroll" section. |
| 8 | 2026-11-17 | Plugin 2.1.0: cancellations reach the portal (the client's 24-month rule) | Plugins > Add New > Upload Plugin > `properx-portal-2.1.0.zip` > Install Now > **Replace current with uploaded** > Activate if needed. Settings are kept. Then, only after Kevin confirms the portal release with `/api/intake/cancellation` is live: Forms > Settings > ProPerX Portal > tick **Send cancellations to the portal** > Save settings. Details: [INSTALL-site.md](INSTALL-site.md), step 6, "Send cancellations". |
| 9 | 2026-11-18 | Single entry /apply: "Who's joining?" (Just me / My agency), plugin 2.2.0 hands "My agency" to the portal | Steps in "Single entry /apply" below, in their order. |

## Item 7: plans page blocks (mirror on production)

The page is /2027-health-plan-options/ (ID 919). The three blocks sit one after another, after "Prescription drug lists".

| Block | Heading after the change | Display Conditions after the change |
|---|---|---|
| Visitors (element `2548199`) | Become a member to enroll | Role / Is Not / Active Member, Pending Member |
| New: under review (`a7c31e4` on the copy; production gets its own ID) | Your application is under review | Role / Is / Pending Member |
| Members (element `001cae9`) | How to enroll | Role / Is / Active Member (unchanged) |

Staff and administrators see the visitors' block when they view the page signed in. In the editor all three are always visible, because conditions apply only on the published page.

### Check before changing anything

1. Pages > All Pages > **2027 Health Plan Options** > **Edit with Elementor**.
2. Click the block headed "How to enroll". In the left panel: **Advanced** tab > **Layout** section > scroll to its end > **Display Conditions** > the icon on the right. Expected: **Role / Is Not / Active Member**.
3. Do the same for the block headed "Ready to enroll?". Expected: **Role / Is / Active Member**.
4. A padlock next to Display Conditions means the Elementor Pro license is not active: activate it first (Elementor > License).
5. If a heading or a condition differs from this, stop and send the developer a screenshot: the live page has changed since the copy was taken.

To undo: the history icon in the editor's top bar > **Revisions** > choose the revision from before this change > **Apply**.

### Steps

**A. Visitors' block** (headed "How to enroll", with the "Apply for membership" button)

1. Click the block. **Content** tab > **HTML Code**. Replace this line:
   `<h2 class="ppx-join__title">How to enroll</h2>`
   with:
   `<h2 class="ppx-join__title">Become a member to enroll</h2>`
2. **Advanced** tab > **Layout** > **Display Conditions** icon. In the row **Role / Is Not / Active Member**, add **Pending Member** to the third field. **Save & Close**.

**B. New block for Pending Members**

1. Right-click the block from A > **Duplicate**. The copy appears right below it; click it.
2. **Content** tab > **HTML Code**: select everything, delete it, and paste the code below.
3. **Advanced** tab > **Layout** > **Display Conditions** icon. Change **Is Not** to **Is**. The third field must hold only **Pending Member**. **Save & Close**.

**C. Members' block** (headed "Ready to enroll?", with "Open the 2027 enrollment form")

1. Click the block. **Content** tab > **HTML Code**. Replace this line:
   `<h2 class="ppx-apply__title">Ready to enroll?</h2>`
   with:
   `<h2 class="ppx-apply__title">How to enroll</h2>`
2. Do not change its Display Conditions.

**D. Publish.** Press **Publish** at the top right. If the site or its host has a page cache, clear it.

**E. Check** in private windows, each signed in as stated:

| Visitor | Sees | Enrollment link (search the page source for `my-benefits`) |
|---|---|---|
| Not signed in | Become a member to enroll | Absent |
| Pending Member | Your application is under review | Absent |
| Active Member | How to enroll, with the enrollment form button | Present |

**F.** Install plugin 2.0.6 or later (item 1).

### Code for step B

```html
<!-- ProPerx 2027 enrollment: application under review. Shown to Pending Members only (Display Conditions).
     No enrollment-form link. Self-contained: scoped CSS (.ppx-review), no scripts, no external files. -->
<div class="ppx-review" id="apply">
  <style>
    .ppx-review{--ppx-navy:#0B2A6B;--ppx-blue:#0A5BB5;--ppx-ink:#14213D;--ppx-muted:#4A5568;font-family:inherit;color:var(--ppx-ink)}
    .ppx-review *{box-sizing:border-box}
    .ppx-review__panel{background:#fff;border:2px solid var(--ppx-blue);border-radius:16px;padding:40px;display:flex;flex-direction:column;gap:16px}
    .ppx-review__eyebrow{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--ppx-blue)}
    .ppx-review__title{margin:6px 0 0;font-size:34px;line-height:1.15;font-weight:800;color:var(--ppx-navy)}
    .ppx-review__text{margin:0;font-size:17px;line-height:1.55;color:var(--ppx-muted);max-width:720px}
    .ppx-review__help{margin:0;font-size:15px;color:var(--ppx-muted)}
    .ppx-review__help a{color:var(--ppx-blue);font-weight:600}
    @media (max-width:767px){.ppx-review__panel{padding:24px}.ppx-review__title{font-size:28px}}
  </style>

  <div class="ppx-review__panel">
    <div>
      <span class="ppx-review__eyebrow">2027 enrollment</span>
      <h2 class="ppx-review__title">Your application is under review</h2>
    </div>
    <p class="ppx-review__text">Thank you for submitting your membership application and completing the payment. Our compliance team is reviewing it, which usually takes 3&ndash;5 business days.</p>
    <p class="ppx-review__text">Once your credentials and details are verified, you will receive a confirmation email with full details regarding your active membership status. The enrollment form link then appears on this page when you sign in.</p>
    <p class="ppx-review__help">Questions? Call us at <a href="tel:+18883881720">+1 (888) 388-1720</a></p>
  </div>
</div>
```

On the copy (2026-11-09) the same result was saved through Elementor's own document save, because the copy's Elementor Pro license is inactive and the Display Conditions dialog is locked there. Each role's view was then checked in a browser. Backup of the page's data, outside the web root: `D:\WORK\OSPanel6\backups\properx-org.local\page-919-before-enroll-fix-2026-11-09.json`.

## Single entry /apply (item 9)

One application page for everyone. "Just me" pays on properx.org as today. "My agency" collects a short block and
continues in the member portal's agency enrollment, on its step 2, prefilled. Done by hand on the live site, in this
order. Each step says where it is done.

### 1. Backups (site)

1. Forms > Import/Export > Export Forms > tick **Membership Application Form** > Download Export File. Keep the
   `.json` file.
2. Forms > Membership Application Form > Settings > Stripe: take a screenshot of the feed "Alliance Membership
   Subscription Feed" (every setting on the page), and the same for Settings > User Registration. Feed settings are
   not part of the form export.
3. Pages > Membership > Edit with Elementor > the arrow next to **Publish/Update** > **Save as Template** > name
   "Membership page before single entry" > Save. Then Templates > Saved Templates > that template > **Export
   Template**. Keep the `.json` file.
4. A full backup by the host (files and database), as before any plugin update.

The local copy's backups of the same three things are in `docs/wordpress/backup/` (kept out of git and out of the KEENEY sync).

### 2. Portal release (KEENEY)

1. The portal release with `/api/intake/agency-start` is deployed; its migration adds three columns to
   `agency_registration_drafts`.
2. Environment (Key Vault or the platform's settings):
   - `AGENCY_ENROLLMENT_ENABLED=true` (the wizard is live).
   - `INDIVIDUAL_MEMBERSHIP_URL=https://properx.org/apply/` ("Just me" on the portal's step 1 comes back here).
   - `AGENCY_HANDOFF_ENABLED` stays **unset** until step 7.
   - Nothing else is new: the handoff is signed with the existing `WP_INTAKE_SECRET`.

### 3. Plugin 2.2.0 (site)

Plugins > Add New > Upload Plugin > `properx-portal-2.2.0.zip` > Install Now > **Replace current with uploaded** >
Activate if needed. Settings are kept.

The new switch **Send agency enrollments to the portal** (Forms > Settings > ProPerX Portal) stays **off** until
step 7. The other switches stay as they are.

### 4. Form changes (site)

**Preferred: the plugin does it.** Forms > Settings > ProPerX Portal > "Application form changes":

1. Form: **Membership Application Form**.
2. Press **Preview changes**. The list must show 61 changes and no red message. A red message ("Field N is missing…",
   "Field N is a …, expected a …") means the live form differs from the copy: stop and send the message to the
   developer.
3. Tick "Apply these changes to the selected form" > **Apply /apply form changes**.
4. A backup of the form as it was is saved first; it is listed under the button with **Download** and **Restore**.
   Download it and keep the file next to the backup of step 1.
5. Press **Preview changes** again: it must say "Nothing to change".

The plugin never touches entries or feeds. Running it twice is safe.

**Fallback: by hand**, in Forms > Membership Application Form (the editor). Field numbers are those of the copy and
of the live form today. New fields get the next free number; the plugin finds them by their **Admin Field Label**
(Field > Advanced), so that label must be typed exactly.

a. New fields at the very top, in this order (drag each above "Personal Information"):

| # | Type | Field label | Admin Field Label | Required | Other settings (General / Appearance / Advanced) |
|---|---|---|---|---|---|
| 1 | Radio Buttons | Who's joining? | `ppx_joining` | yes | Choices, with **show values**: `Just me` = `individual` (default, "selected"), `My agency (2 or more people)` = `agency`. Custom Validation Message: `Choose who is joining.` CSS Class: `ppx-joining`. Advanced > **Allow field to be populated dynamically**, Parameter Name `join`. |
| 2 | Single Line Text | Partner code (optional) | `ppx_partner_code` | no | Description: `From an MGA or FMO partner. Leave blank if you don't have one.` Maximum characters 50. CSS Class `ppx-partner-code`. Field width: half. Allow field to be populated dynamically, Parameter Name `code`. No conditional logic. |
| 3 | HTML | Agency principal | `ppx_agency_heading` | – | Content: `<h3 class="ppx-section-title">Agency principal</h3><p class="ppx-section-text">The owner or person authorized to enroll the agency. You'll manage your agency's membership.</p>` |
| 4 | Single Line Text | First name | `ppx_agency_first_name` | yes | Maximum characters 100. Half width. Autocomplete `given-name`. |
| 5 | Single Line Text | Last name | `ppx_agency_last_name` | yes | Maximum characters 100. Half width. Autocomplete `family-name`. |
| 6 | Email | Email address | `ppx_agency_email` | yes | Description `This will be your agency admin login email.` Half width. No email confirmation. |
| 7 | Phone | Phone number | `ppx_agency_phone` | yes | Phone format: (###) ###-####. Half width. |
| 8 | Single Line Text | Agency name | `ppx_agency_name` | yes | Maximum characters 255. Half width. |
| 9 | Drop Down | About how many people? | `ppx_agency_headcount` | no | Choices with values: `2 to 9 people` = `2-9`, `10 to 49 people` = `10-49`, `50 or more people` = `50+`. Placeholder `Select`. Description `A starting point. Your price adjusts to the people you add.` Half width. |
| 10 | HTML | What happens next | `ppx_agency_next` | – | Content: the block below. |

Content of field 10:

```html
<div class="ppx-next"><h4>What happens next</h4><ol><li>Add your agency details and your NPN.</li><li>Add your people, one at a time or by spreadsheet. Your rate updates as you go.</li><li>Review and pay by card or bank. Everyone gets an email invitation to set up their account.</li></ol>[properx_pricing view="apply-tiers"]</div><p class="ppx-continue-note">You'll continue in the secure member portal. Your progress is saved, so you can finish later.</p>
```

Fields 3 to 10: Conditional Logic > **Show** this field if **All** match: Who's joining? **is** My agency.

b. Every field that was on the form before (Personal Information to "How did you hear…"): Conditional Logic >
**Show** if **All**: Who's joining? **is** Just me. Fields 30 and 31 keep a second rule, see below.

c. Changes to existing fields:

| Field | Change |
|---|---|
| 14 NPN | Admin Field Label `ppx_npn`. Maximum characters 10. Description `The number NIPR assigned to your insurance license, 7 to 10 digits. We use it to verify your license is active.` (The plugin checks 7 to 10 digits.) |
| 15 Insurance license expiration date | No change: it stays, required, until the client decides. |
| 16 Resident License State | Field label `Resident license state`. Required. Description empty (the state's own sub-label explains it). |
| 17 License Type(s) Held | **Delete the field.** Old entries keep their values; the plugin still sends them for those entries. |
| 18 HTML "Membership Selection" | Content: replace `Membership Selection` with `Membership`. |
| 28 Select Your Membership Plan | Admin Field Label `ppx_plan`. The one choice "Individual Membership": tick it as the default (selected). The plugin writes its text and price ($169 with $199 struck through until 2026-12-31, then $199). |
| 29 Payment Method | Field label `Payment`. Admin Field Label `ppx_payment_method`. Description `The 2.9% surcharge applies to credit cards only. Debit cards and bank payments have no surcharge.` Choices with values: `Credit card <span class="ppx-choice-note">2.9% surcharge</span>` = `credit` (default), `Debit card` = `debit`, `Bank account (ACH) <span class="ppx-choice-note">No surcharge</span>` = `ach`. |
| 30 Credit Card Surcharge (3%) | Field label `Credit card surcharge (2.9%)`. Formula `{Membership:28} * 0.029` (pick field 28 from the merge tag list, then type ` * 0.029`). Rounding: 2 decimals. Conditional Logic: Show if **All**: Who's joining? is Just me, **and** Payment is Credit card. CSS class `gform_hidden` stays. |
| 31 ACH Payment Notice | Conditional Logic: Show if **All**: Who's joining? is Just me, **and** Payment is Bank account (ACH). |
| 24 consent | Field label `Billing, refunds and the 24-month rule`. Checkbox label `I understand membership is billed annually, dues are non-refundable, and a canceled member can't reapply for 24 months.` |
| 32 consent | Field label `Automatic renewal`. Checkbox label `I understand my membership renews each year unless I cancel before the renewal date.` |
| 25 consent | Field label `Terms of Service and Privacy Policy`. Checkbox label `I have read and agree to the ProPerX Alliance <a href="/terms-of-service/" target="_blank">Terms of Service</a> and <a href="/privacy-policy/" target="_blank">Privacy Policy</a>.` |

The three agreements are placeholders from the design until KEENEY sends the final wording; in the plugin they sit in
one place (`PROPERX_PORTAL_AGREEMENTS`), and running the form changes again applies a new wording.

d. New field after "Payment Information" (19): **HTML**, label `Order summary`, Admin Field Label `ppx_order_summary`,
CSS Class `ppx-order-summary`, Conditional Logic: Show if Who's joining? is Just me. Content:

```html
<div class="ppx-summary" data-ppx-summary><div class="ppx-summary-row"><span>Individual membership, 1 year</span><span data-ppx-summary-plan></span></div><div class="ppx-summary-row" data-ppx-summary-surcharge-row><span>Credit card surcharge (2.9%)</span><span data-ppx-summary-surcharge></span></div><div class="ppx-summary-row ppx-summary-total"><span>Total today</span><span data-ppx-summary-total></span></div><p class="ppx-summary-note">The surcharge applies to credit cards only. Debit cards and bank payments have no surcharge.</p></div>
```

e. Form settings:

- Form Settings > Form Layout > Form CSS Class Name: add ` ppx-apply` (keep `apply-form`).
- Submit button text: `Join ProPerX Alliance` (the page shows "Pay $173.90 and join…" or "Continue to agency
  enrollment" itself).
- Notifications > **New Membership Request Staff Notification** and **User Pending Confirmation**: Enable
  conditional logic > Send if **All**: Who's joining? is Just me.
- Notifications > Add New: name `Payment method differs (staff)`, Event **ProPerX: payment method differs from the
  one chosen**, Send to the same address as the staff notification, Subject
  `Application {entry_id}: payment method differs from the one chosen`, Message `Stripe charged application
  {entry_id} with another kind of payment than the one chosen on the form (credit card, debit card or bank account),
  so the 2.9% credit card surcharge may be wrong. The entry's notes say which. Open the entry: {entry_url}`.
- Confirmations > Add New: name `My agency (portal handoff off)`, Type Text, message `Thank you. ProPerX will contact
  you shortly to enroll your agency. Questions? Call 888-388-1720.`, Conditional Logic: Use if Who's joining? is My
  agency. The Default Confirmation stays as it is.

Save the form.

### 5. Stripe and User Registration feeds (site, always by hand)

Forms > Membership Application Form > Settings > **Stripe** > "Alliance Membership Subscription Feed" > edit:

1. **Setup Fee**: tick Enabled, choose the product **Credit card surcharge (2.9%)**. The surcharge is then charged
   once, with the first payment, and never on the yearly renewal (until now it was part of the form total and so of
   every renewal). Recurring Amount stays **Form Total**: Gravity Forms leaves the setup fee out of it.
2. **Conditional Logic**: tick Enable Condition > Process this feed if **All**: Who's joining? is Just me.
3. Update Settings.

Settings > **User Registration** > "Create User from From": no change. It already runs only when the entry has a
payment status (Active, Paid, Processing, Pending), which a "My agency" entry never has.

Check on the copy (Stripe test mode): a credit card paid $173.90 the first time (invoice: $169 + "Setup fee"
$4.90) and the subscription renews at $169 a year; a debit card paid $169.

### 6. Pricing section on the Membership page (site)

Filled in with the pricing section work (template import and where it goes).

### 7. Turn the handoff on

1. Portal (KEENEY): `AGENCY_HANDOFF_ENABLED=true`.
2. Site: Forms > Settings > ProPerX Portal > tick **Send agency enrollments to the portal** > Save settings. The
   connection test runs on save and must say "Connected".

### 8. Final test entry

On the live site real cards are charged, so the test is one small, refunded payment, done by a person who can refund
in Stripe:

1. Open `https://properx.org/apply/`: "Just me" is chosen, the plan shows $169 with $199 struck through, the summary
   shows $169.00 + $4.90 = $173.90 for "Credit card", $169.00 for "Debit card" and "Bank account (ACH)".
2. Open `https://properx.org/apply/?join=agency&code=TEST`: "My agency" is chosen, the partner code is filled, only
   the agency block shows, the button says "Continue to agency enrollment". Fill it with a real colleague's details
   (not a member), press the button: the portal opens on "2. Tier" (or on "1. Membership type" with an error if the
   code is not valid). Close it; nothing was charged. The entry has the note "Agency enrollment opened in the portal".
3. A "Just me" application with a real credit card of the team: the entry gets "Payment method check: OK (credit
   card)." and "Sent to portal: OK (created)". In Stripe the first invoice shows the setup fee line, the subscription
   $169/year. Then refund the payment and cancel the subscription in Stripe, and remove the member in the portal (or
   tell KEENEY).

### 8a. ACH on the copy (by hand)

Stripe shows its bank dialog with a captcha, so this is checked in a normal browser, not by a script. On the copy
(with `local-ach-test.php`, L9): "Just me" > Payment: Bank account (ACH) > in the payment box "US bank account" >
email and name > Pay > in Stripe's dialog **Test Institution** > account **Success** > Connect account > back on the
form the payment is submitted (status Processing). After a few minutes Stripe's webhook marks it paid; the entry gets
"Payment method check: OK (bank account (ACH))." and "Sent to portal".

### 9. Roll back

- **Handoff only:** untick **Send agency enrollments to the portal** (site), or unset `AGENCY_HANDOFF_ENABLED`
  (portal). With either off, "My agency" shows the form's own confirmation instead of the portal; nothing is charged.
- **Plugin:** Plugins > Add New > Upload Plugin > `properx-portal-2.1.0.zip` > Replace current with uploaded. The
  stored settings stay; 2.1.0 ignores the new switch.
- **Form:** Forms > Settings > ProPerX Portal > "Application form changes" > the backup > **Restore** (fields,
  notifications and confirmations as they were; entries and feeds stay). Or Forms > Import/Export > Import Forms with
  the file from step 1: it is imported as a **new** form, so then point the /apply page and the plugin's Form ID at it,
  or prefer Restore.
- **Feeds:** undo step 5 by hand (untick Setup Fee and the condition).
- **Page:** filled in with the pricing section work.

## Local only (never on production)

| # | Date | Change | Steps / why |
|---|---|---|---|
| L1 | 2026-11-05 | Local only: Mailpit instead of Disable Emails | `wp-content/mu-plugins/local-mailpit.php` (runs only when `WP_ENVIRONMENT_TYPE` is `local`) sends all mail to Mailpit on this machine, SMTP 127.0.0.1:1025, inbox http://localhost:8025. Disable Emails (installed 2026-11-03) was removed: its must-use loader and `wp-content/plugins/disable-emails/`. The copy has no Gravity SMTP connection, so WordPress's own `wp_mail()` runs. **Before connecting Gravity SMTP on the copy, remember that form 1 mails the real staff address on every test submission.** |
| L2 | 2026-11-03 | `WP_ENVIRONMENT_TYPE` = `local` in `wp-config.php` | Allows the connector to skip TLS verification for the self-signed OSPanel certificate. **On production this constant must be absent or `production`.** The setup checklist shows it red here, as expected. |
| L3 | 2026-11-05 | Plugin installed from the zip with local values | Forms > Settings > ProPerX Portal: URL `https://properx-portal.local`, form `1`, the Secret equal to `WP_INTAKE_SECRET` in the portal's `.env`, both switches on. The must-use connector (`wp-content/mu-plugins/properx-portal-connector.php`) and its three constants in `wp-config.php` were removed. Production generates its own secret. |
| L4 | 2026-11-05 | Test signing secret from `stripe listen` | Forms > Settings > Stripe > Webhooks > Test Signing Secret: the `whsec_...` printed by `stripe listen --all-snapshot --forward-to "https://properx-org.local/?callback=gravityformsstripe" --skip-verify` (and, for the portal, `stripe listen --all-snapshot --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify`), so Gravity Forms accepts forwarded test events locally. Production keeps its own Live secret. Both `stripe listen` must run during a test application. |
| L5 | 2026-11-03 | Local only: Stripe Connect cleared locally, reconnected to developer's test account; never on production | The copy had inherited the client's Stripe Connect authorization. In the copy's database only, the test keys, `test_auth_token` and the test signing secret were blanked in `gravityformsaddon_gravityformsstripe_settings`, and `gform_stripe_last_webhook_processed` was removed. Disconnect was deliberately not used and nothing was sent to Stripe, because revoking the authorization could affect the live site. Mode stays Test; feeds unchanged. Then: Forms > Settings > Stripe > "Connect with Stripe" with the developer's test account. Backup outside the web root: `D:\WORK\OSPanel6\backups\properx-org.local\`. **Never on production: do not clear or disconnect Stripe there.** |
| L6 | 2026-11-03 | Local only, never on production: manual Stripe keys instead of Connect | Connect cannot finish on the copy: after OAuth it returns `webhook_error` because Stripe does not accept the local webhook address. `wp-content/mu-plugins/local-stripe-manual-keys.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) adds `gform_stripe_connect_enabled` = false on admin screens only (on the form it would remove the payment field) and `gform_stripe_manual_webhook_configuration` = true. The second filter is what shows the signing secret fields in add-on 7.0. A third filter, on reading `gravityformsaddon_gravityformsstripe_settings`, treats a `pk_test_` key as valid when the Test secret key is valid: the add-on checks publishable keys in the browser by tokenizing a raw card number, Stripe can refuse that for a correct key, and the "invalid" flag hides the Webhooks section. What the failed OAuth left in the settings was cleared in the copy's database (backup in the same folder). Then the developer enters Test keys at Forms > Settings > Stripe, saves, and enters the Test Signing Secret in the Webhooks section that appears after saving. |
| L7 | 2026-11-17 | Plugin 2.1.0 on the copy, "Send cancellations to the portal" on; one test cancellation | Installed from `dist/properx-portal-2.1.0.zip` over 2.0.6 (settings kept) and the switch ticked. Check: entry 28 (dummy address, Stripe test mode) had its test subscription canceled in the Sandbox; Gravity Forms marked it Cancelled, the plugin noted "Cancellation sent to portal: OK", and the portal marked the member canceled with access until the paid date. A new paid application with the same NPN (entry 29, test card 4242) got the note "not added: canceled less than 24 months ago". For the check the Test Signing Secret (L4) was set to the `stripe listen --api-key` secret and then put back. Entry 29's test subscription is still active in the Sandbox. |
| L8 | 2026-11-18 | Plugin 2.2.0 on the copy, "Send agency enrollments to the portal" on; backups of form 1 and the Membership page | Installed from `dist/properx-portal-2.2.0.zip` over 2.1.0 (settings kept) and the switch ticked. The portal's local `.env` has `AGENCY_HANDOFF_ENABLED=true`. Before any change, form 1 (with its feeds) and page 20 were exported to `docs/wordpress/backup/`. Check: a signed call with fictional data opened a new draft on step 2 (Agency Plus); the same link a second time showed "This link can't be used any more". |
| L9 | 2026-11-19 | Form 1 changed by "Apply /apply form changes" (plugin 2.2.0), Stripe feed by hand (step 5); `local-ach-test.php`; test applications | The form changes ran three times as they grew (backups in the plugin's list); a fourth preview says "Nothing to change". Stripe feed: Setup Fee = "Credit card surcharge (2.9%)", condition "Who's joining? is Just me". `wp-content/mu-plugins/local-ach-test.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) asks the Payment Element for card and bank explicitly, because the developer's Stripe test account has bank payments off in its payment method settings; production keeps the automatic methods from the client's Stripe settings. **Never on production.** Test entries 30 to 36 (fictional people, `@properx-site-check.com` and `@whitfield-insurance-check.com`): "My agency" 30 to 32 (no payment, no user); "Just me" 33 credit card ($173.90 first, $169/year), 34 debit card ($169), 35 declined card (Failed), 36 debit chosen but credit card paid (note "chose debit card, paid with credit card"); an ACH attempt stopped at Stripe's captcha and saved no entry. Test subscriptions of 33, 34 and 36 are active in the Stripe Sandbox; the portal created members for them locally. |
