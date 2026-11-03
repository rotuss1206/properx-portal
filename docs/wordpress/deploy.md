# Connecting properx.org to the member portal: production steps

For Kevin. After these steps, a paid membership application on properx.org (Gravity Forms form 1) creates the member in the portal (https://shn-portal.keeney.cloud).

Time needed: about 30 minutes, plus one test application.

## 1. Merge the PR

Merge and deploy. The pipeline's `migrate --force` adds the `intake_submissions` table, `member_profiles.stripe_subscription_id` and the API Service permission `members.intake.create`.

The endpoint stays off (404) until step 4.

## 2. Generate the secret

Generate it yourself and set the same value in both places. **Never send it by email or chat.**

```
openssl rand -hex 32
```

- Portal: Key Vault / platform environment, `WP_INTAKE_SECRET`
- WordPress: `wp-config.php`, step 5

## 3. WAF

Allow the properx.org server's outbound IP to reach `/api/intake/*` on the portal, and only that path.

## 4. Enable the endpoint

In the portal environment, set `INTAKE_ENABLED=true` and restart.

Leave `INTAKE_SEND_INVITE` unset for now. While it is off, new members get no "set your password" email from the portal. Turn it on when the portal is announced to members.

Check from the properx.org server. An unsigned request must answer 401 (404 means the flag is off; a timeout means the WAF):

```
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://shn-portal.keeney.cloud/api/intake/individual
```

## 5. Install the plugin on properx.org

1. Upload `integrations/wordpress/properx-portal-connector.php` to `wp-content/mu-plugins/`. Create the folder if it is missing.
2. In `wp-config.php`, above the "stop editing" line, add:

   ```php
   define( 'PROPERX_PORTAL_URL', 'https://shn-portal.keeney.cloud' );
   define( 'PROPERX_PORTAL_SECRET', 'the value from step 2' );
   define( 'PROPERX_PORTAL_FORM_ID', 1 );
   ```

3. Make sure `WP_ENVIRONMENT_TYPE` is **not** `local`. It is fine if it is absent. `local` turns off TLS verification.
4. Plugins > Must-Use should list "ProPerx Portal Connector". A yellow notice in wp-admin means a constant is missing.

Before going on, check these on the live site:

- **WP-Cron runs.** The plugin queues deliveries with it (the site has no Action Scheduler). If `DISABLE_WP_CRON` is set, a server cron must call `wp-cron.php` every few minutes.
- **The Gravity Forms Stripe webhook is active** in Live mode (Forms > Settings > Stripe, signing secret filled in). Without it, payments stay in "Processing" and nothing is sent.
- **The portal's own Stripe webhook** (`/api/webhooks/stripe`) receives Live events with `STRIPE_WEBHOOK_SECRET` set. It sets the paid period and payment status.

## 6. One live test

Submit one application at https://properx.org/apply with a real card and a mailbox you control.

| Where | What to check |
|---|---|
| Gravity Forms entry | Payment status Active; a note "Sent to portal: OK (created)". Allow a few minutes for WP-Cron. |
| Portal, Nova > Member Accounts | The member exists with the Member role and license verification "Pending review" |
| Portal, Nova > Member Profiles | NPN, state, license expiry and address are filled in; payment status Active and a paid-through date (from the Stripe webhook) |
| Portal, Nova > Audit Log | `member_intake_created`, actor API Service |

Clean up afterwards:

1. In Stripe, refund the payment and cancel the subscription.
2. In Nova, delete the test member.
3. In Gravity Forms, trash the entry, and delete the WordPress user it created.

## 7. Troubleshooting

Everything is in the entry's notes (Forms > Entries > the entry). They hold no personal data.

| Note | Meaning | What to do |
|---|---|---|
| `failed: HTTP 404` | `INTAKE_ENABLED` is off | Enable it; the plugin retries by itself |
| `failed: HTTP 401` | Secrets differ, or the server clock is more than 5 minutes off | Compare the values without sending them anywhere; check NTP |
| `failed: HTTP 403` or `connection error` | WAF or network | Step 3 |
| `failed: HTTP 429` or `503` | Rate limited, or the portal is down | Retried automatically |
| `failed: invalid fields: npn` | The entry's data does not pass validation; no retries | Correct the entry, then "Resend to portal" |
| `OK (linked)` | The email already belongs to a member; only blank fields were filled | Nothing; differences are named in the note and in `member_intake_linked` |
| `OK (conflict)` | The email belongs to staff or to an agency's person; nothing was changed | Handle by hand |
| No note at all | Payment still "Processing", or WP-Cron is not running | Check the Stripe webhook and WP-Cron |

Retries: 5 min, 15 min, 1 h, 3 h, 6 h, 12 h (about 22 hours). After that, resend by hand:

- **One entry:** the entry's side panel, "ProPerx Portal" box, **Resend to portal**.
- **Several entries:** `wp properx-portal send --form=1 --since=YYYY-MM-DD`

Repeats are safe: the portal answers a known entry with the stored result and creates nothing new.

## 8. Optional: send existing paid applications

Dry run first. It prints entry IDs only and changes nothing:

```
wp properx-portal send --form=1 --dry-run
```

Then, once the list looks right:

```
wp properx-portal send --form=1
```

Only active (not trashed) entries with payment status Active or Paid are sent. Entries already sent are skipped. Check that test entries are trashed first, because every entry on the list becomes a portal member.

## Settings to repeat on production

From [changes.md](changes.md):

- Upload the mu-plugin (step 5).
- Add the three `wp-config.php` constants (step 5).

No WP admin settings need to change. Do **not** repeat the entries marked "local only" in `changes.md`, above all Disable Emails.
