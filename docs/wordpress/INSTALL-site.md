# Installing the ProPerX Portal plugin on properx.org

For whoever installs it, using only the WordPress admin. No file access is needed. Kevin takes part in step 4, and the portal side is his: [deploy.md](deploy.md), with the variables in [portal-env.example](portal-env.example).

**You need:** the file `properx-portal-<version>.zip`, an Administrator login, and about 30 minutes plus one test application with a real card.

Everything stays off until step 6. Installing and activating the plugin changes nothing for visitors.

## 1. Backup

Take a full backup first: All-in-One WP Migration > Export, or your host's backup. Keep it until the test in step 7 has passed.

## 2. Upload and activate

1. Plugins > Add New > **Upload Plugin** > choose the zip > **Install Now**.
2. **Activate Plugin**.

The plugin is now listed as "ProPerX Portal".

## 3. Enter the Portal URL and Form ID

Forms > Settings > **ProPerX Portal**:

1. **Portal URL:** the address Kevin gives you, starting with `https://`.
2. **Form ID:** the number of "Membership Application Form" in the ID column under Forms. It is 1 on the copy; check it on the live site.
3. **Save settings.**

Only Administrators see this page.

## 4. Kevin pastes the Secret

Kevin opens the same page and pastes the **Secret** himself, then presses **Save settings**. He generates it and sets the same value as `WP_INTAKE_SECRET` in the portal. **It should never travel by email or chat.**

After saving, the field stays empty and shows a green **Saved** badge with the time of the last change. The value is stored encrypted and cannot be read back. Paste a new value only to replace it.

If Kevin cannot log in, he can type it into the field during a screen share. Do not write it down.

## 5. Test connection

Saving the settings runs the test by itself; **Test connection** runs it again. The last result stays beside the button, with its time, and in the checklist.

| Result | Meaning | What to do |
|---|---|---|
| Connected | All good | Go on |
| 401 | The Secret differs from `WP_INTAKE_SECRET`, or the server clock is off | Kevin pastes it again; ask the host about the clock |
| 403 | The WAF blocks the request | Kevin allows this server's outbound IP for `/api/intake/*` |
| 404 | `INTAKE_ENABLED` is off, or the URL is wrong | Kevin turns it on; check the URL |
| No answer within 15 seconds | Firewall or network | Ask the host about outbound access |

## 6. Turn on sending

Look at the **Setup checklist** at the top of the page. Red lines come first. When "Sending to the portal is on" is the only red line left, tick **Send paid applications to the portal** and press **Save settings**. The checklist then folds into one line, "All checks passed"; click it to see the list.

Leave **Approval email** off until the client has approved the text ([emails.md](emails.md)). While it is off, members get the two old emails, as they do today.

### What to do about a red line

| Red line | What to do |
|---|---|
| Portal URL and secret are set | Steps 3 and 4. "Can no longer be read" means the site's security salts changed: Kevin pastes the Secret again |
| This is not a local environment | `WP_ENVIRONMENT_TYPE` is `local` in `wp-config.php`, which turns off TLS checks. Ask the host to remove it. It must never be `local` on the live site |
| The form exists and has a Stripe feed | Wrong Form ID, or the form lost its feed: check Forms > the form > Settings > Stripe |
| Gravity Forms Stripe is in Live mode with a signing secret | Forms > Settings > Stripe: set Live mode and fill in the Live Signing Secret. Without it, bank (ACH) payments stay in Processing and are never sent |
| WP-Cron works | Just installed: open any page, wait a minute, and reload. If it stays red, ask the host whether `wp-cron.php` is blocked, or whether a server task must call it every few minutes |
| Test connection failed | The line gives the reason and its time; see the table in step 5 |
| Test connection succeeded, with a red mark | Not tested yet, or the URL or Secret changed: press Test connection |
| Sending to the portal is on | Step 6 |

## 7. One application and a refund

1. Open https://properx.org/apply and apply with a real card and a mailbox you control.
2. Check the following:

   | Where | What you should see |
   |---|---|
   | Your mailbox | One "Your Membership Application is Under Review", after the payment |
   | Forms > Entries > the entry | Payment status Active; after a few minutes a note "Sent to portal: OK (created)" |
   | Portal (Kevin) | The member exists, license verification "Pending review", payment status Active |

3. If the Approval email is already on: Users > the new user > **Approve & Activate**. You should get exactly one email, "Welcome to ProPerX Alliance — Your Membership Is Approved". Check that the logo shows and open the links. Set the password, sign in, and open the plans page: "Ready to enroll?" should be visible.

   > If the logo is missing, the site's Gravity SMTP uses an API connection rather than SMTP. Tell the developer; the email still works.

4. Clean up:
   1. In Stripe, refund the payment and cancel the subscription.
   2. Kevin deletes the test member in the portal.
   3. Trash the entry in Forms > Entries, and delete the WordPress user.

**If an entry has no note:** the payment is still Processing, or WP-Cron is not running. A note saying `failed` names the cause, and the plugin retries by itself. The full list is in [deploy.md](deploy.md), "Troubleshooting". To send by hand, use the entry's side panel: **Resend to portal**.

**To undo:** untick the switches. Deactivating the plugin removes nothing. Deleting it removes its settings and leaves the notes on the entries.

## Changes in the admin

These are separate from the plugin. Do them after step 7. Each is also logged in [changes.md](changes.md).

### 1. Send "User Pending Confirmation" after payment

Today it says "payment received" on form submission, before the payment is confirmed.

Forms > **Membership Application Form** > Settings > Notifications > **User Pending Confirmation** > in **Event** choose **Subscription Created** > **Update Notification**.

The plugin must be active with the Form ID set first: Stripe can report a new subscription twice at the same moment, and the plugin makes this email go once.

### 2. Make License State and License Type required

Forms > **Membership Application Form** (opens the editor) > click **Resident License State** > General > tick **Required**. Do the same for **License Type(s) Held**. **Save Form**.

### 3. Unpublish /apply-test

Pages > All Pages > **Apply Test** > Quick Edit > Status: **Draft** > **Update**. It is a public copy of the paid form.

### 4. Turn off WP Dummy Content Generator

Plugins > Installed Plugins > **WP Dummy Content Generator** > **Deactivate**.

### Do not repeat these

Anything marked "local only" in `changes.md`, above all Mailpit, the manual Stripe keys and `WP_ENVIRONMENT_TYPE=local`.
