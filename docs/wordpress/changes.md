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

## Local only (never on production)

| # | Date | Change | Steps / why |
|---|---|---|---|
| L1 | 2026-11-05 | Local only: Mailpit instead of Disable Emails | `wp-content/mu-plugins/local-mailpit.php` (runs only when `WP_ENVIRONMENT_TYPE` is `local`) sends all mail to Mailpit on this machine, SMTP 127.0.0.1:1025, inbox http://localhost:8025. Disable Emails (installed 2026-11-03) was removed: its must-use loader and `wp-content/plugins/disable-emails/`. The copy has no Gravity SMTP connection, so WordPress's own `wp_mail()` runs. **Before connecting Gravity SMTP on the copy, remember that form 1 mails the real staff address on every test submission.** |
| L2 | 2026-11-03 | `WP_ENVIRONMENT_TYPE` = `local` in `wp-config.php` | Allows the connector to skip TLS verification for the self-signed OSPanel certificate. **On production this constant must be absent or `production`.** The setup checklist shows it red here, as expected. |
| L3 | 2026-11-05 | Plugin installed from the zip with local values | Forms > Settings > ProPerX Portal: URL `https://properx-portal.local`, form `1`, the Secret equal to `WP_INTAKE_SECRET` in the portal's `.env`, both switches on. The must-use connector (`wp-content/mu-plugins/properx-portal-connector.php`) and its three constants in `wp-config.php` were removed. Production generates its own secret. |
| L4 | 2026-11-05 | Test signing secret from `stripe listen` | Forms > Settings > Stripe > Webhooks > Test Signing Secret: the `whsec_...` printed by `stripe listen --all-snapshot --forward-to "https://properx-org.local/?callback=gravityformsstripe" --skip-verify` (and, for the portal, `stripe listen --all-snapshot --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify`), so Gravity Forms accepts forwarded test events locally. Production keeps its own Live secret. Both `stripe listen` must run during a test application. |
| L5 | 2026-11-03 | Local only: Stripe Connect cleared locally, reconnected to developer's test account; never on production | The copy had inherited the client's Stripe Connect authorization. In the copy's database only, the test keys, `test_auth_token` and the test signing secret were blanked in `gravityformsaddon_gravityformsstripe_settings`, and `gform_stripe_last_webhook_processed` was removed. Disconnect was deliberately not used and nothing was sent to Stripe, because revoking the authorization could affect the live site. Mode stays Test; feeds unchanged. Then: Forms > Settings > Stripe > "Connect with Stripe" with the developer's test account. Backup outside the web root: `D:\WORK\OSPanel6\backups\properx-org.local\`. **Never on production: do not clear or disconnect Stripe there.** |
| L6 | 2026-11-03 | Local only, never on production: manual Stripe keys instead of Connect | Connect cannot finish on the copy: after OAuth it returns `webhook_error` because Stripe does not accept the local webhook address. `wp-content/mu-plugins/local-stripe-manual-keys.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) adds `gform_stripe_connect_enabled` = false on admin screens only (on the form it would remove the payment field) and `gform_stripe_manual_webhook_configuration` = true. The second filter is what shows the signing secret fields in add-on 7.0. A third filter, on reading `gravityformsaddon_gravityformsstripe_settings`, treats a `pk_test_` key as valid when the Test secret key is valid: the add-on checks publishable keys in the browser by tokenizing a raw card number, Stripe can refuse that for a correct key, and the "invalid" flag hides the Webhooks section. What the failed OAuth left in the settings was cleared in the copy's database (backup in the same folder). Then the developer enters Test keys at Forms > Settings > Stripe, saves, and enters the Test Signing Secret in the Webhooks section that appears after saving. |
