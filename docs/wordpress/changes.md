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
