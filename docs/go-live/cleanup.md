# Removing test data from the portal

For Kevin. How to remove the test accounts made before go-live, with `php artisan app:purge-test-data` in the
portal's container console.

The command removes only the accounts you list. It never guesses. Without `--force` it only shows what it would
remove.

## What it removes

For each selected account:

- the account itself, with its roles, sessions, passkeys and password reset links;
- the member profile, its agency invitations, certificate issues and the cached certificate PDFs;
- license documents and tax documents, including the stored (encrypted) files in Blob Storage;
- in-portal notifications, notification settings and reminder log entries;
- intake submissions from properx.org linked to the member, so a later "Resend to portal" creates the member
  again;
- the portal's local records of Stripe events for the member's or agency's Stripe customer;
- agency enrollment drafts whose principal email is listed or is on a listed domain.

An **agency** is removed when its principal is selected and nobody else on the agency stays, together with its
pending license copies. If other people stay, the agency is kept and loses its principal. The command says so;
assign a new principal in Nova.

## What it keeps

- Roles, permissions and the other reference data, the API Service account, the library and partner codes.
- Every account that is not listed. `--domain` never selects staff: a staff account goes only when its exact email
  is listed.
- The last Executive Director: the command refuses a selection that holds every Executive Director.
- The audit log. The run adds one record, "test_data_purged", with counts only and no emails.

**Stripe is not touched.** Test-mode customers and subscriptions stay in Stripe. Remove them in the Stripe
Dashboard, in test mode or the sandbox: delete the test customers under **Customers** (deleting a customer cancels
its subscriptions), or use Stripe's "Delete all test data" where the account offers it. Live-mode data is never
part of this clean-up.

## How to select

| Option | Selects |
|---|---|
| `--email=a@b.com` | That account. Repeat the option for more. |
| `--domain=b.com` | Every member account on `@b.com` (exactly that domain, not its subdomains). Repeatable. Staff never. |
| `--file=/tmp/test-accounts.txt` | Every email in the file, one per line. Empty lines and lines starting with `#` are skipped. |
| `--demo` | Exactly the accounts the demo seeders create: `director@`, `coordinator@`, `compliance@`, `member01@` to `member30@`, `principal01@` to `principal03@` and the agency people `agency01.person02@` etc., all `@properx.test`. Also the `DEMO_LOGIN_EMAIL` plus addresses when that setting is present. The full list is printed. |

The options can be combined.

## Steps

1. **Dry run.** Nothing is removed:

   ```bash
   php artisan app:purge-test-data --email=first.tester@example.com --domain=test-agency.com
   ```

2. **Check the output.**
   - The first table lists every account that would go. Make sure no real member or staff member is in it.
   - "No account" lines show listed emails that do not exist.
   - "Kept, staff matched by a domain only" lists staff the domain did not select.
   - "Agency #N is kept" means an agency keeps other people.
   - The second table counts what goes, by kind.

3. **Remove.** Run the same command with `--force`:

   ```bash
   php artisan app:purge-test-data --email=first.tester@example.com --domain=test-agency.com --force
   ```

   It ends with "Removed N accounts". If some stored files could not be deleted, it says how many; the records are
   gone either way, and the log has the error class.

4. **Stripe (test mode).** Delete the test customers and subscriptions in the Stripe Dashboard, as above.

5. **properx.org.** Test entries and WordPress users on the site are not touched by this command. Remove them in
   WordPress (Forms > Entries, Users) if they should go too.

To check the result, run the dry run again: it should show the accounts under "No account".
