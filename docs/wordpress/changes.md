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

## Local only (never on production)

| # | Date | Change | Steps / why |
|---|---|---|---|
| L1 | 2026-11-05 | Local only: Mailpit instead of Disable Emails | `wp-content/mu-plugins/local-mailpit.php` (runs only when `WP_ENVIRONMENT_TYPE` is `local`) sends all mail to Mailpit on this machine, SMTP 127.0.0.1:1025, inbox http://localhost:8025. Disable Emails (installed 2026-11-03) was removed: its must-use loader and `wp-content/plugins/disable-emails/`. The copy has no Gravity SMTP connection, so WordPress's own `wp_mail()` runs. **Before connecting Gravity SMTP on the copy, remember that form 1 mails the real staff address on every test submission.** |
| L2 | 2026-11-03 | `WP_ENVIRONMENT_TYPE` = `local` in `wp-config.php` | Allows the connector to skip TLS verification for the self-signed OSPanel certificate. **On production this constant must be absent or `production`.** The setup checklist shows it red here, as expected. |
| L3 | 2026-11-05 | Plugin installed from the zip with local values | Forms > Settings > ProPerX Portal: URL `https://properx-portal.local`, form `1`, the Secret equal to `WP_INTAKE_SECRET` in the portal's `.env`, both switches on. The must-use connector (`wp-content/mu-plugins/properx-portal-connector.php`) and its three constants in `wp-config.php` were removed. Production generates its own secret. |
| L4 | 2026-11-05 | Test signing secret from `stripe listen` | Forms > Settings > Stripe > Webhooks > Test Signing Secret: the `whsec_...` printed by `stripe listen --all-snapshot --forward-to "https://properx-org.local/?callback=gravityformsstripe" --skip-verify` (and, for the portal, `stripe listen --all-snapshot --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify`), so Gravity Forms accepts forwarded test events locally. Production keeps its own Live secret. Both `stripe listen` must run during a test application. |
| L5 | 2026-11-03 | Local only: Stripe Connect cleared locally, reconnected to developer's test account; never on production | The copy had inherited the client's Stripe Connect authorization. In the copy's database only, the test keys, `test_auth_token` and the test signing secret were blanked in `gravityformsaddon_gravityformsstripe_settings`, and `gform_stripe_last_webhook_processed` was removed. Disconnect was deliberately not used and nothing was sent to Stripe, because revoking the authorization could affect the live site. Mode stays Test; feeds unchanged. Then: Forms > Settings > Stripe > "Connect with Stripe" with the developer's test account. Backup outside the web root: `D:\WORK\OSPanel6\backups\properx-org.local\`. **Never on production: do not clear or disconnect Stripe there.** |
| L6 | 2026-11-03 | Local only, never on production: manual Stripe keys instead of Connect | Connect cannot finish on the copy: after OAuth it returns `webhook_error` because Stripe does not accept the local webhook address. `wp-content/mu-plugins/local-stripe-manual-keys.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) adds `gform_stripe_connect_enabled` = false on admin screens only (on the form it would remove the payment field) and `gform_stripe_manual_webhook_configuration` = true. The second filter is what shows the signing secret fields in add-on 7.0. A third filter, on reading `gravityformsaddon_gravityformsstripe_settings`, treats a `pk_test_` key as valid when the Test secret key is valid: the add-on checks publishable keys in the browser by tokenizing a raw card number, Stripe can refuse that for a correct key, and the "invalid" flag hides the Webhooks section. What the failed OAuth left in the settings was cleared in the copy's database (backup in the same folder). Then the developer enters Test keys at Forms > Settings > Stripe, saves, and enters the Test Signing Secret in the Webhooks section that appears after saving. |
