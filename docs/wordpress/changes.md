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
| 10 | 2026-11-20 | Go-live application (plugin 2.3.0): license number required, NPN optional, license types with "Other", license copy upload sent to the portal, the revised confirmation, the agent welcome email | Steps in "Go-live application (plugin 2.3.0)" below, in their order. |
| 11 | 2026-11-20 | Health insurance enrollment deadline: December 20 for a January 1 start | Steps in "Health enrollment deadline: December 20" below. |
| 12 | 2026-11-20 | /apply look: license copy control, struck-through price, no founding badge, "(Optional)" markers, no character counters, aligned inputs and address, payment choices as cards, no surcharge line, no jump of the payment box, Stripe Link off; the page's intro text | Plugin 2.3.0 (nothing to do beyond item 10) and one text by hand: "/apply look (plugin 2.3.0)" below. |
| 13 | 2026-11-20 | Feeds of the duplicated form (Stripe subscription, User Registration), made by hand; do right before the first real payment test | Steps in "Feeds of the duplicated form" below. |

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
2. Press **Preview changes**. The list must show no red message (with plugin 2.3.0 it also holds the changes of "Go-live application (plugin 2.3.0)", step 5). A red message ("Field N is missing…",
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
| 17 License Type(s) Held | **Keep it** (plugin 2.3.0, go-live requirements): it gets "Other", see "Go-live application (plugin 2.3.0)", step 5, row c. (Plugin 2.2.0 deleted it.) |
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

Settings > **User Registration** > "Create User from From": it runs only when the entry has a payment status, which a
"My agency" entry never has. Its condition loses "Processing" (Active / Paid / Pending only), so no user is made
before the payment: see "Feeds of the duplicated form", C, "Decision".

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
(with `local-ach-test.php`, L9): "Just me" > Payment: Bank account (ACH) > the payment box shows the bank form (plugin 2.3.0: no tabs) >
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

## Go-live application (plugin 2.3.0)

The go-live requirements (items 3 to 6 and the agent welcome). Done after "Single entry /apply" above, or
together with it: the form changes of 2.3.0 include those of 2.2.0. In this order:

### 1. Portal release (KEENEY)

1. The portal release with `/api/intake/license-copy` is deployed (its migration adds `license_document_id` to
   `intake_submissions`). The intake now accepts an application with a license number and no NPN.
2. The ingress and the WAF accept request bodies of **15 MB** on `/api/intake/license-copy` (a 10 MB file is about
   13.4 MB once encoded). With a smaller limit the copy is refused with HTTP 413 and the entry keeps it, retrying.
3. Nothing new in the portal's environment for the site. (The agency owner's welcome has its own settings:
   `AGENCY_WELCOME_*`, see `docs/wordpress/portal-env.example`.)

### 2. Backups (site)

As in "Single entry /apply", step 1, item 1: Forms > Import/Export > Export Forms > **Membership Application Form**
> Download Export File. Keep the file. And the four Elementor documents of the next section, each saved as a
template (Edit with Elementor > the arrow next to **Update** > **Save as Template**).

### 3. Plugin 2.3.0 (site)

Plugins > Add New > Upload Plugin > `properx-portal-2.3.0.zip` > Install Now > **Replace current with uploaded** >
Activate if needed. Settings are kept.

### 4. Welcome email links (site)

Forms > Settings > ProPerX Portal > **Welcome email links**:

| Field | Value |
|---|---|
| Health insurance enrollment link | The enrollment link the client gives for the agent welcome ("[Health Insurance Enrollment Link]") |
| Provider search tool link | The RBP provider search link (pending from the client) |

Save settings. An empty field leaves its section ("1. ENROLL IN HEALTH INSURANCE", "2. FIND A PROVIDER") out of
the email. Both must start with `https://`.

### 5. Form changes (site)

Forms > Settings > ProPerX Portal > "Application form changes" > Form **Membership Application Form** >
**Preview changes**. The list must show no red message and contain these (numbers of new fields on live may
differ). Live's form as exported on 2026-11-23 (`docs/wordpress/backup/live-forms-2026-11-23.json`, not in git)
gave 93 changes on a fresh import, then none:

- Field 33 "Resident License Number" (live's own, added there after our backup) becomes the license number:
  label "License number", our description, required, 50 characters, Admin Field Label `ppx_license_number`. On a
  form without such a field: a new field "License number" (text, required) before field 14
- Field 34 "Partner Code" (live's own, near the payment) becomes the partner code and moves to the top, under
  "Who's joining?": label "Partner code (optional)", half width, `?code=` (links with `?partner=` still fill it), no
  input mask (live's allowed five characters only). No second partner code field is added
- "License fields in this order": License number (full width), NPN beside the expiration date, Resident license
  state (alone; its select half the width on wide screens), License type(s) held, Other license type, Copy of your
  license. Live had the NPN beside "Resident License Number" and the state beside the expiration date
- Field 17 "License type(s) held": choices Life / Health / Property & Casualty / Annuity / Variable / Other (when
  the live form still has field 17), or a new field "License type(s) held" after field 16 (when it was deleted);
  either way displayColumns "3", displayAlignment "horizontal" (three per row, "Other" ending the second row);
  required (live made it optional)
- New field "Other license type" after the license types (shown when "Other" is ticked)
- New field "Copy of your license" (file upload, PDF, JPG, PNG, up to 10 MB)
- Field 14 NPN: not required, label "NPN (National Producer Number) (optional)", our description (live says 7–10
  digits too). Its Admin Field Label `agent_npn` (set on live) stays: the plugin knows it as the NPN
- "Next field id": above every field (Gravity Forms' own counter; without it a field added later in the form
  editor could take the id of one added here)

If the helper ran before on this form (the live duplicate "Membership Application Form (test)" of 2026-11-23 has
our "License number" 46 and "Partner code (optional)" 36 besides live's 33 and 34), the preview also lists
`Field 46 "License number" removed` and `Field 36 "Partner code (optional)" removed` ("no entry has a value in
it"). A copy that entries already use is not removed: it is hidden from the page (Visibility: Administrative)
and renamed `…_old`, so nothing reads it.
- Notification "Agent welcome" (event "ProPerX: application paid or bank payment started")
- Notification "New Membership Request Staff Notification": event "Subscription Created" (paid applications only)
- Notification "User Pending Confirmation": inactive (the agent welcome replaces it)
- Confirmation "Default Confirmation": the client's revised text

Tick "Apply these changes to the selected form" > **Apply /apply form changes**. Download the backup listed under
the button. **Preview changes** again: "Nothing to change".

**Fallback by hand** (Forms > Membership Application Form). Every field below: Conditional Logic **Show** if
**All**: Who's joining? is Just me.

| # | Where | Type | Field label | Admin Field Label | Required | Other settings |
|---|---|---|---|---|---|---|
| a | Right above field 14 (NPN): field 33 "Resident License Number" moved there (a new field only when the form has none) | Single Line Text | License number | `ppx_license_number` | yes | Maximum characters 50. Description: `As printed on your resident insurance license. We use it to verify your license is active.` Field width: full, alone in its row |
| a2 | Field 34 "Partner Code", moved right under "Who's joining?" | Single Line Text | Partner code (optional) | `ppx_partner_code` | no | Description: `From an MGA or FMO partner. Leave blank if you don't have one.` Maximum characters 50. Input mask: off. Allow field to be populated dynamically: on, parameter name `code`. Custom CSS class `ppx-partner-code`. Field width: half. Conditional Logic: none (both kinds of application) |
| b | Field 14 | (existing) | `NPN (National Producer Number) (optional)` | keep `agent_npn` (or `ppx_npn` when empty) | **no** | Description: `The number NIPR assigned to your insurance license, 7 to 10 digits, if you have it.` Field width: half, in one row with field 15 (expiration date, half); field 16 (state) below them, full width, alone |
| c | Field 17 (or a new Checkboxes field right after field 16 when 17 is gone) | Checkboxes | License type(s) held | `ppx_license_types` | yes | Choices: Life, Health, Property & Casualty, Annuity, Variable, **Other** (add Other last; keep the others as they are). Appearance: **Display in columns**, **3** columns, horizontal. Field width: full |
| d | Right after c | Single Line Text | Other license type | `ppx_license_type_other` | yes | Maximum characters 100. Conditional Logic: Show if **All**: Who's joining? is Just me **and** License type(s) held is Other |
| e | Right after d | File Upload | Copy of your license | `ppx_license_copy` | yes | Allowed file extensions `pdf,jpg,jpeg,png`. Maximum File Size `10`. Multiple files: off. Description: `A photo or scan of your current resident insurance license: PDF, JPG or PNG, up to 10 MB. It speeds up the verification of your license.` |

Confirmations > **Default Confirmation** > Type Text, message (the editor's Text tab):

```html
<h3>Membership Application Submitted Successfully!</h3><p>Thank you for applying to join ProPerX Alliance!</p><p>We've received your application, and our team will verify your active insurance license using the license number you provided. Watch your inbox for a welcome email with your next steps, including how to log in to your member portal and start accessing your benefits.</p><p>Please note: Membership is exclusively available to actively licensed insurance professionals.</p><p>We look forward to welcoming you to ProPerX Alliance!</p>
```

Notifications > Add New: name `Agent welcome`, Send To **Select a Field** > Email address (field 7), From Name and
From Email as in "User Pending Confirmation", Subject `Welcome to ProPerX Alliance! Your Next Steps`, Event
**ProPerX: application paid or bank payment started** (it is in the list once plugin 2.3.0 is active), Conditional
Logic: Send if Who's joining? is Just me, Message: the text of `properx_portal_welcome_message()` in the plugin's
`includes/welcome-email.php` (the "Preferred" way above writes it exactly; by hand it is easy to get wrong). Then
**New Membership Request Staff Notification** > Event **Subscription Created**, and **User Pending Confirmation** >
the toggle in the notifications list **off**.

The copy of the license never stays in the site's uploads: as soon as the entry is saved, the plugin encrypts it
into `wp-content/uploads/properx-portal-private/` (web access refused), removes the original and the entry's link,
and deletes it once the portal has it. Copies of applications never paid go after 30 days.

### 6. Check (site)

The final test entry of "Single entry /apply", step 8, item 3, with a license number, no NPN, Health and Other
("Surplus lines") and a PDF copy. Expected: the confirmation above; the applicant gets "Welcome to ProPerX
Alliance! Your Next Steps" at once; the entry's notes say "License copy received…" and later "Sent to portal: OK
(created; license copy stored in License Documents)"; the entry shows no file; in the portal the member's License
Documents hold the copy, pending review.

### 7. Roll back

Form: "Application form changes" > the backup > **Restore**. Plugin: upload `properx-portal-2.2.0.zip` with
"Replace current with uploaded" (2.2.0 sends no license copy; copies waiting in the private folder stay until
2.3.0 is back, or are deleted by hand).

## Health enrollment deadline: December 20

Go-live requirements, item 6: the health insurance enrollment deadline for a January 1 start becomes
**December 20**. On the copy these places said **December 31** (the live site may say December 30, changed after
the copy was taken): change whichever date is there. The **founding rate** dates ("Founding member special ·
expires December 31, 2026", "Lock in your founding rate before December 31", "Founding Member rate ends
12/31/2026" in the child theme's custom CSS) are a different date and **stay**: the founding rate ends 2027-01-01
00:00 Eastern in the portal and the plugin.

Each with **Edit with Elementor** > click the widget > change it > **Update**:

| Where (live URL) | Widget | Before (on the copy) | After |
|---|---|---|---|
| Header, every page (Templates > Theme Builder > Header "Elementor Header #74") | Heading, the top alert bar | ALERT: Enroll by **DEC 31, 2026**, for the group health plan starting JAN 1, 2027. … | ALERT: Enroll by **DEC 20, 2026**, for the group health plan starting JAN 1, 2027. … |
| Home (`/`) | Countdown, above "Members who join by…" | Due Date `2026-12-31 16:36` | Due Date `2026-12-20 23:59` |
| Home (`/`) | Text Editor under the countdown | Members who join by **December 31, 2026**, can enroll in the group health plan starting January 1, 2027. | Members who join by **December 20, 2026**, can enroll … |
| Home (`/`) | Icon Box "A nationwide PPO network…" | … Effective January 1, 2027. Join by **December 31st, 2026**. | … Join by **December 20th, 2026**. |
| Benefits (`/benefits/`) | Icon Box | To be included in the January 1, 2027 group health plan, you must join by **December 31, 2026**. | … by **December 20, 2026**. |
| Membership (`/membership/`) | Nested Accordion (FAQ), item title | What if I miss the **December 31, 2026** deadline? | What if I miss the **December 20, 2026** deadline? |

Also searched on the copy, with nothing to change: Gravity Forms (fields, notifications, confirmations), widgets
and options, the theme and must-use plugins, the plugin's emails, and the portal (code, emails, pages). Clear the
site's page cache, if any, after the change. Elementor keeps the earlier version under Revisions.

## /apply look (plugin 2.3.0)

The form's look before going live. Everything below except step 2 ships in plugin 2.3.0 (its stylesheet and
script, `assets/apply.css` and `assets/apply.js`, and `includes/apply-form.php`) and works by itself once
"Go-live application (plugin 2.3.0)", step 3, is done: nothing to change in the form for it.

| # | What changes on the page | How |
|---|---|---|
| 1 | "Copy of your license": the browser's "Choose File" button becomes a box like the other inputs ("Choose file" + the file's name, same height and corners); keyboard: Tab reaches it, Space or Enter opens the file dialog; a file that is not PDF/JPG/PNG or is over 10 MB gets "Choose a PDF, JPG or PNG file." / "This file is larger than 10 MB. Choose a smaller one." under it | Plugin (the file input stays, visually hidden, so Gravity Forms works as before) |
| 2 | The page's intro text | **By hand**, see below |
| 3 | Price: "~~$199/yr~~ $169/yr" in the plan and "~~$199~~ $169 per year" in the "Just me" card (until 2026-12-31, then $199 alone) | Plugin |
| 4 | The theme's yellow "FOUNDING MEMBER RATE · ENDS 12/31/2026" badge in the plan is hidden on this form (the plan's description says it). The rule stays in Appearance > Customize > Additional CSS for other pages; nothing to change there | Plugin |
| 5 | "Partner code (Optional)", "Business/Agency Name (Optional)", "NPN … (Optional)": the marker looks like "(Required)", in grey | Plugin |
| 6 | No "0 of 50 max characters" counters; the fields keep their maximum length | Plugin |
| 7 | Descriptions under the inputs, so inputs side by side start at the same height (Email / Confirm email, Phone / Business name, the "My agency" block) | Plugin (on the page only; the form's own setting is not changed) |
| 8 | Address: Google's street address search box gets the same border, corners and focus ring as the other inputs (its inside, with the magnifier, is Google's). Its suggestions open below it, over the fields under it; on phones Google shows them full screen | Plugin |
| 8b | "Resident license state": the select half the width on wide screens (as the NPN beside the expiration date), full width on phones, whatever the page's own styles; "License type(s) held": three per row (set by "Apply /apply form changes", the same on the live form's field 17 and on a new field); "Copy of your license": its description once (Gravity Forms' own "Accepted file types…" line hidden) | Plugin and "Apply /apply form changes" |
| 9 | "Payment": Credit card / Debit card / Bank account (ACH) as three equal cards in one row (one under the other on phones), like "Who's joining?". The whole card (not only its words) chooses it, here and in "Who's joining?"; hover and keyboard focus show on the card | Plugin |
| 9b | One payment choice only: the cards drive Stripe's payment box. Credit or debit card: it asks for a card only; Bank account (ACH): for a bank account only. Stripe's own "Card / US bank account" tabs no longer show. The payment is made with the same type on the server, and the order summary and the button's amount follow the chosen card | Plugin |
| 10 | "Credit card surcharge (2.9%) Price: $4.90" never shows (it only feeds the total and the order summary) | Plugin |
| 11 | "My agency" then "Just me": the payment box keeps exactly its height and shows "Loading the secure payment form…" while Stripe builds it again, instead of Stripe's grey placeholders and a jump; "Your payment is processed securely…" under it stays where it is | Plugin |
| 12 | Stripe Link is off on this form ("Secure, fast checkout with Link", "Save my information for faster checkout"): the Payment Element offers card and US bank account (ACH), with Link turned off on it | Plugin. Nothing in Stripe's dashboard; Link stays available to the client's other Stripe forms |
| 13 | After a submission with errors the form looks as before it: "Copy of your license" keeps its own box (not the browser's file button), a file chosen before stays chosen and its name shows in the box (Gravity Forms keeps it; nothing to choose again), "Optional" markers and the payment cards stay. Each error shows under its field in red, as on every other field, and the field's border turns red | Plugin and "Apply /apply form changes" (errors under the fields) |

The street address suggestions work on the copy with the site's own Google Maps key (no referrer error), so the key
is not limited to properx.org only; on live, check them once after installing (type a street on /apply).

Item 12 means the Payment Element on /apply offers exactly cards and bank accounts, whatever else is switched on
in the client's Stripe account (Apple Pay and Google Pay are part of cards and stay). If the client wants another
method there, it is added in the plugin (`PROPERX_PORTAL_PAYMENT_METHODS`). Approved by the client: cards (Apple Pay and
Google Pay included) and ACH only.

After the plugin is installed on live, check by hand in a normal browser (Stripe's bank dialog has a captcha, so
this is not scripted): choose each payment card a few times, then pay a small real amount by card and refund it
("Single entry /apply", step 8, item 3). Gravity Forms Stripe writes "This Element has already been destroyed" in the
browser's console when "My agency" is chosen after switching the payment cards; it comes from Gravity Forms Stripe
itself (it also happens without the plugin's script) and changes nothing for the visitor.

### Step 2, by hand: the intro text above the form

Pages > **Apply for Membership** (`/apply/`) > **Edit with Elementor** > the text under the heading "Membership
Application" (Text Editor widget) > replace the first sentences:

| Before | After |
|---|---|
| Join our alliance, and we'll notify you when our application form goes live. We'll use your National Producer Number (NPN) to verify your active insurance license. | Join our alliance. Our team will verify your active insurance license using the license number you provide. |

Keep the rest of the widget as it is ("**Membership is open exclusively to licensed insurance professionals.**" and
"Call us at (440) 776-7379 …"). **Update**, then clear the page cache, if any. The wording follows the client's
revised confirmation ("our team will verify your active insurance license using the license number you
provided"); "we'll notify you when our application form goes live" went too, since the form is live.

### Check

On `/apply/` at full width and on a phone: the points of the table above; then a "Just me" test application with a
PDF copy and a "My agency" start, as in "Go-live application (plugin 2.3.0)", step 6.

## Feeds of the duplicated form (do right before the first real payment test)

**Do this right before the first real payment test**, not earlier: an active Stripe feed on the duplicate takes real
payments from anyone who reaches its page.

The duplicate of the Membership Application form on live has **no feeds** (Gravity Forms did not copy them), so it
takes no payment and creates no user. Both feeds are made by hand, mirroring the original form's feeds as they are
on live (values below from the export of the original form's feeds; compare them with the original's screens
before saving). Field names are those of the duplicate after "Apply /apply form changes" (same field labels as the
original; the numbers can differ, pick fields by their labels).

Before you start: Forms > Settings > ProPerX Portal > Form ID is the duplicate (see "Go-live application",
pre-flight), and the duplicate has had "Apply /apply form changes" (it needs "Who's joining?" and "Credit card
surcharge (2.9%)").

### A. Stripe feed

Forms > (the duplicate) > Settings > **Stripe** > **Add New**:

| # | Setting | Value | Original live feed |
|---|---|---|---|
| 1 | Name | `Alliance Membership Subscription Feed` | same |
| 2 | Transaction Type | **Subscription** | same |
| 3 | Subscription Name | `Alliance Membership Subscription` | same |
| 4 | Recurring Amount | **Select Your Membership Plan** (the plan only: $169/year, $199 from 2027) | Form Total (Gravity Forms leaves the setup fee out of it, so the amount is the same; the plan field says it plainly) |
| 5 | Billing Cycle | **1 Year** | same |
| 6 | Setup Fee | **Enabled**, product **Credit card surcharge (2.9%)** (charged once, with the first payment, credit cards only) | Disabled on the original, which put the surcharge into every renewal; this is the change of "Single entry /apply", step 5 |
| 7 | Trial | off | same |
| 8 | Customer Information > Name | **Name** (the whole name) | same |
| 8 | Customer Information > Email | **Email address** | same |
| 8 | Customer Information > Description | **Select Your Membership Plan** | same |
| 8 | Customer Information > Coupon | (none) | same |
| 9 | Billing Information > Address, Address 2, City, State, Zip, Country | the **Address** field's Street Address, Address Line 2, City, State, ZIP Code, Country | same |
| 10 | Metadata | none (leave the empty row) | none |
| 11 | Options / Post Payment Actions | tick **Create user (User Registration) only when payment is received** (the wording of the Stripe add-on: "delay … until the payment is received") | ticked |
| 12 | Cancellation action | off | same |
| 13 | Conditional Logic | **Enable Condition** > Process this feed if **All**: **Who's joining?** is **Just me** | none on the original (it had no "Who's joining?") |

**Update Settings.** The feed list must show it **Active** (the toggle in the list).

### B. User Registration feed (still used)

Still needed: it creates the WordPress user ("Pending Member") that the site's "Approve & Activate", the approval
email and the plans page's member blocks rely on. Forms > (the duplicate) > Settings > **User Registration** >
**Add New**:

| # | Setting | Value (as the original) |
|---|---|---|
| 1 | Feed Name | `Create User from From` (the original's name, kept so staff recognise it) |
| 2 | Action | **Create User** |
| 3 | Username | **Email address** |
| 4 | First Name | **Name (First)** |
| 5 | Last Name | **Name (Last)** |
| 6 | Nickname | **Name** (the whole name) |
| 7 | Display Name | **Nickname** |
| 8 | Email Address | **Email address** |
| 9 | Password | **Send email link** (the person sets it) |
| 10 | Role | **Pending Member** |
| 11 | User Meta | none |
| 12 | Send Email | **ticked** (as on the original) |
| 13 | User Activation | off |
| 14 | Registration Condition | **Enable** > Create the user if **Any**: Payment Status **is Active** / **is Paid** / **is Pending** (three rules; **not** Processing, see C below) |

**Update Settings**, Active. A "My agency" entry has no payment status, so it never creates a user.

### B2. Webhooks feed of the original form: recreate on the duplicate if present

The original form on live has a **Webhooks** add-on feed (Forms > the original > Settings > **Webhooks**); its
settings are not in the form export, so they are copied by hand, screen by screen. If the original has no Webhooks
feed, skip this. Forms > (the duplicate) > Settings > **Webhooks** > **Add New**, the same values as the original:

| # | Setting | Value |
|---|---|---|
| 1 | Name | the original's name |
| 2 | Request URL | the original's URL, exactly |
| 3 | Request Method | as the original |
| 4 | Request Format | as the original |
| 5 | Request Headers | as the original. A header that carries a key or token: copy it from the original's screen into the new one, never through email or chat |
| 6 | Request Body | as the original. **All Fields**: nothing else to do (the duplicate sends its new fields too). **Select Fields**: the same keys, each mapped to the field of the **same label** on the duplicate (field 14 keeps its Admin Field Label `agent_npn`; the license number is field 33 "License number", the partner code field 34 "Partner code (optional)") |
| 7 | Conditional Logic | **Enable** > Process this feed if **All**: **Who's joining?** is **Just me**, plus the original's own rules, if any. A "My agency" entry is not an individual application and has no payment |

**Update Settings**, Active. Then the Stripe feed (A) > Options / Post Payment Actions: if the original's Stripe feed
delays the webhook until the payment is received (a "Webhooks" line under Post Payment Actions), tick it the same
way on the duplicate's.

After the first real payment test (D): the entry's notes show the webhook's result ("Webhook sent" or Gravity Forms'
error), the same as an entry of the original form.

### C. Emails only after payment

No email about an application goes before its payment: a declined card gets none. "Apply /apply form changes" sets
this up on the duplicate (Go-live application, step 5); check it in Settings > Notifications:

| Notification | Event | Active | Why |
|---|---|---|---|
| Agent welcome | **ProPerX: application paid or bank payment started** | yes | The plugin's own event. Gravity Forms Stripe saves the entry before the payment, so "Form is submitted" would also go for a declined card. The event goes **once** per entry: right after the payment step when Stripe says the first payment **succeeded** (card) or is **processing** (bank account, ACH; the plugin asks Stripe, never the browser), or else when the subscription starts |
| New Membership Request Staff Notification | **Subscription Created** | yes | Paid applications only. For a card: seconds after the payment. For ACH: once the bank has cleared it (up to 4 business days), the moment Gravity Forms Stripe starts the subscription. The plugin sends it once even when Stripe reports twice |
| User Pending Confirmation ("Your Membership Application is Under Review") | (unchanged) | **no** | The agent welcome replaces it. Nothing depends on it: the plugin's "send once" filter only removes duplicates, and the approval flow ("Approve & Activate", the approval email, the KEENEY plugin's and the theme's emails) and the User Registration feed's own email do not use it |
| Payment method differs (staff) | ProPerX: payment method differs from the one chosen | yes | After the payment, by the plugin's check |

Why not Gravity Forms' own events for the welcome: "Subscription Created" comes for ACH only when the bank has cleared
the payment (days later, not "when processing"); "Payment Pending" is sent by Gravity Forms Stripe only for one-off
payments, not for a subscription's first payment. The plugin's event covers both cases.

Checked on the copy (2026-11-23): a credit card application got one "Welcome to ProPerX Alliance! Your Next Steps" and
the staff email after the payment, no "Under Review"; a declined card got no email. ACH: by hand (8a), the welcome
is expected right after the bank step, while the entry still says "Processing".

**Decision (2026-11-23): no user before the payment.** The User Registration feed (B) no longer creates the user
while the entry is "Processing" (before the payment result): on the copy a declined card had still got a Pending
Member user and the admin's "New User Registration" email. B's condition is **Active / Paid / Pending** only:

- card: the user is created seconds after the payment (Gravity Forms Stripe marks the entry Active);
- bank account (ACH): the user is created when the bank payment succeeds (up to 4 business days); the agent welcome
  still goes as soon as the payment starts.

**The same change applies to the original live form at cutover:** its User Registration feed "Create User from From"
today has Active / Paid / **Processing** / Pending. Forms > (the original form) > Settings > User Registration > the
feed > Registration Condition > remove the rule "Payment Status is Processing" > Update Settings. Do it at the moment
/apply switches to the new form (or earlier: it only stops users being made for unpaid entries).

### D. Then the first real payment test

Right after A to C: "Single entry /apply", step 8, item 3 on the duplicate's draft page (Preview): a small real
credit card payment of the team, then a refund and the subscription canceled in Stripe, and the test user deleted
(and the member in the portal, once sending is on). Check the entry: "Subscription has been created", "Subscription
has been paid. Amount: $173.90" (credit card), "Payment method check: OK (credit card)", a Pending Member user; in
Stripe the first invoice has the "Setup fee" line and the subscription is $169/year.

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
| L10 | 2026-11-20 | Plugin 2.3.0 on the copy, form changes applied, deadline changed; test applications | Installed from `dist/properx-portal-2.3.0.zip` over 2.2.0 (settings kept). "Apply /apply form changes" applied 11 changes (fields 44 to 47: license number; license types as a new field 45, because the copy had lost field 17; "Other license type"; license copy); a second preview says "Nothing to change". Welcome email links left empty on the copy (no real links yet). Deadline: the six places of "Health enrollment deadline" changed through Elementor's own save; their Elementor data before the change and form 1 are in `docs/wordpress/backup/before-2.3.0/`. Test applications (fictional, `@properx-site-check.com`, test card 4242): one without license data stopped by the form's validation; entry 39 paid, its copy reached the portal's License Documents and left the site's uploads, the agent welcome reached Mailpit. |
| L16 | 2026-11-23 | Live's changed form simulated on the copy | Plugin 2.3.0 rebuilt and installed (the helper reuses live's "Resident License Number" and "Partner Code", one order and layout for the license block, Gravity Forms' next field id). "Apply /apply form changes" on form 1: 2 changes (the license number's width and row), then none. Live's export of 2026-11-23 imported twice: the original (93 changes, then none) and the duplicate "(test)" after the earlier helper (27 changes: our extra "License number" and "Partner code" removed, live's moved and renamed; then none). With the plugin's Form ID pointed at each in turn, both pages looked like form 1's at 1440 and 390 (same fields, order, rows and widths; Stripe's box stays empty there, the imports have no Stripe feed). The imported forms (no entries) and their pages were deleted and the Form ID set back to 1. |
| L15 | 2026-11-23 | User Registration on the copy; the form after a failed submission | Form 1's User Registration feed: the "Payment Status is Processing" rule removed (Active, Paid, Pending stay; item 13, C). Plugin 2.3.0 rebuilt and installed with item 12, row 13; "Apply /apply form changes" on form 1: errors under the fields. Checked at 1440 and 390 on /apply (form 1): submitted empty, then with some fields, then complete; the license copy chosen once before the first submission. Test entries 47 and 48 paid with the test card, each sent to the portal with its license copy. |
| L14 | 2026-11-23 | Address suggestions and the license block on the copy; live simulation | Plugin 2.3.0 rebuilt and installed: the address box no longer cuts off Google's suggestions (they show at 1440 and, full screen, at 390). "Apply /apply form changes" on form 1 (already changed): 4 changes, the license types' columns; a second run: none. A fresh import of `docs/wordpress/backup/apply-form-1.json` (as live has it, field 17 kept) took 75 changes, a second run none; its license block then looked the same as form 1's at 1440 and 390 (three per row, the state's select aligned and half width, the copy's description once). The imported forms and their draft pages were deleted afterwards. |
| L13 | 2026-11-23 | Emails only after payment on the copy | Plugin 2.3.0 rebuilt and installed; "Apply /apply form changes" applied 3 changes to form 1 (Agent welcome on the plugin's event, staff email on Subscription Created, User Pending Confirmation inactive); a second preview says "Nothing to change". Entry 44 (credit card 4242): one agent welcome and the staff email after the payment, no "Under Review". Entry 45 (declined card 4000000000000002): no email to the applicant or staff, but the User Registration feed created a Pending Member user before the payment failed (item 13, C, open point). |
| L12 | 2026-11-20 | /apply payment choice on the copy | Plugin 2.3.0 rebuilt and installed with item 12, rows 9, 9b and 11. Checked at 1440 and 390 px: the description under the payment box stays put while switching "Just me" / "My agency"; a click on an empty part of any card chooses it; the three payment cards switch Stripe's box between card and bank account without its tabs. Test entries 42 (credit card 4242, $173.90) and 43 (debit card 4000056655665556, $169.00) paid, "Payment method check: OK", copies in the portal. The ACH payment stops at Stripe's captcha in the scripted browser: to be done by hand (8a). |
| L11 | 2026-11-20 | /apply look on the copy; intro text changed | Plugin 2.3.0 rebuilt and installed with the look of item 12. Page 26's intro text changed through Elementor's own save (its Elementor data before the change: `docs/wordpress/backup/before-2.3.0/page-26-elementor-data.json`). Checked at 1440 and 390 px; test entry 40 ("Just me", test card 4242, PDF copy) paid and reached the portal with its copy, and a "My agency" start opened the portal's step 2. On the copy, `local-ach-test.php` (L9) also asks for card and bank: the same list the plugin now sets. |
