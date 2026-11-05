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

The connection is tested in step 5, with a button.

## 5. Install the plugin on properx.org

The site's side is done in the WordPress admin, with no file access: [INSTALL-site.md](INSTALL-site.md). All portal variables are in [portal-env.example](portal-env.example).

1. Plugins > Add New > Upload Plugin > `properx-portal-<version>.zip` > Activate.
2. Forms > Settings > ProPerX Portal: enter the Portal URL (`https://shn-portal.keeney.cloud`) and the Form ID.
3. **Paste the value from step 2 into Secret yourself** and save. It is stored encrypted and shows only "set". If you do have file access, the constants `PROPERX_PORTAL_URL`, `PROPERX_PORTAL_SECRET` and `PROPERX_PORTAL_FORM_ID` in `wp-config.php` take priority and lock the fields.
4. The Setup checklist on that page must go green. Above all, `WP_ENVIRONMENT_TYPE` must **not** be `local`, which turns off TLS verification.
5. **Test connection.** It sends a signed request that changes nothing and says in plain words what is wrong:

   | Result | Meaning |
   |---|---|
   | Connected (200) | All good |
   | 401 | The Secret differs from `WP_INTAKE_SECRET`, or the server clock is off |
   | 403 | The WAF blocks the request (step 3) |
   | 404 | `INTAKE_ENABLED` is off, or the URL is wrong (step 4) |
   | No answer within 15 seconds | Firewall or network |

   Without shell access this button is all you need. WP-CLI is an alternative, not a requirement.

6. Tick **Send paid applications to the portal** and save. It is off by default: until then nothing is sent.

The checklist also covers WP-Cron and the Gravity Forms Stripe webhook in Live mode. Check these on the portal's side:

- **The portal's own Stripe webhook** (`/api/webhooks/stripe`) receives Live events with `STRIPE_WEBHOOK_SECRET` set. It sets the paid period and payment status.
- **`STRIPE_SECRET` in the portal environment** (optional but recommended): a Stripe **restricted key** with read access to Subscriptions only. After each application a queued job reads the subscription, so the paid period is right even if a webhook is missed. While it is empty, the job is skipped and only webhooks set the period.

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
| `Not sent to portal: sending is off` | The switch was turned off while this was queued | Turn it on, then "Resend to portal" |
| No note at all | Payment still "Processing", WP-Cron is not running, or sending was off when it was paid | Check the Setup checklist; "Resend to portal" sends what was paid while it was off |

Retries: 5 min, 15 min, 1 h, 3 h, 6 h, 12 h (about 22 hours). After that, resend by hand:

- **One entry:** the entry's side panel, "ProPerX Portal" box, **Resend to portal**.
- **Several entries:** Forms > Entries, tick the entries, Bulk actions > **Resend to portal**.
- **Alternative with shell access:** `wp properx-portal send --form=1 --since=YYYY-MM-DD`

Repeats are safe: the portal answers a known entry with the stored result and creates nothing new.

## 8. Optional: send existing paid applications

In Forms > Entries, filter to the paid entries, tick them and use Bulk actions > **Resend to portal**. Or, with shell access, use WP-CLI. Dry run first; it prints entry IDs only and changes nothing:

```
wp properx-portal send --form=1 --dry-run
```

Then, once the list looks right:

```
wp properx-portal send --form=1
```

Only active (not trashed) entries with payment status Active or Paid are sent. WP-CLI skips entries already sent; the bulk action sends them again, which is safe because the portal answers a known entry with the stored result. Check that test entries are trashed first, because every entry on the list becomes a portal member.

## Settings to repeat on production

From [changes.md](changes.md); the exact clicks are at the end of [INSTALL-site.md](INSTALL-site.md):

- The plugin and its settings (step 5).
- "User Pending Confirmation" on the Subscription Created event.
- Resident License State and License Type(s) Held required.
- /apply-test unpublished, WP Dummy Content Generator off.

Do **not** repeat the entries marked "local only" in `changes.md`, above all Mailpit.
