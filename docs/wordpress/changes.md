# WordPress changes log

Every change made to the local copy of properx.org (`D:\WORK\OSPanel6\home\properx-org.local`, https://properx-org.local).

- **Mirror on production** = Kevin repeats it on properx.org by hand, using the steps below.
- **Local only** = must never be repeated on production.

No secret values are written here.

## Mirror on production

| # | Date | Change | Steps |
|---|---|---|---|
| 1 | see `deploy.md` | Portal connector mu-plugin | Upload `integrations/wordpress/properx-portal-connector.php` to `wp-content/mu-plugins/`. Create the folder if it does not exist. |
| 2 | see `deploy.md` | Portal connector constants | In `wp-config.php`, above the "stop editing" line, add `PROPERX_PORTAL_URL`, `PROPERX_PORTAL_SECRET` and `PROPERX_PORTAL_FORM_ID` (`deploy.md`, step 5). |

There are no settings changes in WP admin to mirror so far.

## Local only (never on production)

| # | Date | Change | Steps / why |
|---|---|---|---|
| L1 | 2026-11-03 | Disable Emails 1.8.3 installed in must-use mode | Copied the wordpress.org plugin to `wp-content/plugins/disable-emails/` and its loader `mu-plugin/disable-emails-mu.php` to `wp-content/mu-plugins/`. The plugin list shows it as inactive; the must-use loader is what runs it. Must-use mode is needed because Gravity SMTP also replaces `wp_mail()`, and the first definition wins. Checked: `wp_mail` resolves to `plugins/disable-emails/includes/bootstrap.php`. Without this, form 1 mails the real staff address on every test submission. |
| L2 | 2026-11-03 | `WP_ENVIRONMENT_TYPE` = `local` in `wp-config.php` | Allows the connector to skip TLS verification for the self-signed OSPanel certificate. **On production this constant must be absent or `production`.** |
| L3 | 2026-11-03 | Connector installed with local values | `wp-content/mu-plugins/properx-portal-connector.php` (a copy from this repository) and the constants in `wp-config.php`: portal URL `https://properx-portal.local`, form ID `1`, and a local secret equal to `WP_INTAKE_SECRET` in the portal's `.env`. Production generates its own secret. |
| L4 | pending | Test signing secret from `stripe listen` | Forms > Settings > Stripe > Webhooks > Test Signing Secret: the `whsec_...` printed by `stripe listen --all-snapshot --forward-to "https://properx-org.local/?callback=gravityformsstripe" --skip-verify` (and, for the portal, `stripe listen --all-snapshot --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify`), so Gravity Forms accepts forwarded test events locally. Production keeps its own Live secret. |
| L5 | 2026-11-03 | Local only: Stripe Connect cleared locally, reconnected to developer's test account; never on production | The copy had inherited the client's Stripe Connect authorization. In the copy's database only, the test keys, `test_auth_token` and the test signing secret were blanked in `gravityformsaddon_gravityformsstripe_settings`, and `gform_stripe_last_webhook_processed` was removed. Disconnect was deliberately not used and nothing was sent to Stripe, because revoking the authorization could affect the live site. Mode stays Test; feeds unchanged. Then: Forms > Settings > Stripe > "Connect with Stripe" with the developer's test account. Backup outside the web root: `D:\WORK\OSPanel6\backups\properx-org.local\`. **Never on production: do not clear or disconnect Stripe there.** |
| L6 | 2026-11-03 | Local only, never on production: manual Stripe keys instead of Connect | Connect cannot finish on the copy: after OAuth it returns `webhook_error` because Stripe does not accept the local webhook address. `wp-content/mu-plugins/local-stripe-manual-keys.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) adds `gform_stripe_connect_enabled` = false on admin screens only (on the form it would remove the payment field) and `gform_stripe_manual_webhook_configuration` = true. The second filter is what shows the signing secret fields in add-on 7.0. A third filter, on reading `gravityformsaddon_gravityformsstripe_settings`, treats a `pk_test_` key as valid when the Test secret key is valid: the add-on checks publishable keys in the browser by tokenizing a raw card number, Stripe can refuse that for a correct key, and the "invalid" flag hides the Webhooks section. What the failed OAuth left in the settings was cleared in the copy's database (backup in the same folder). Then the developer enters Test keys at Forms > Settings > Stripe, saves, and enters the Test Signing Secret in the Webhooks section that appears after saving. |
