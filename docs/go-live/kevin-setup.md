# Go-live setup: portal and properx.org

For Kevin. What is left to put the go-live release live: the portal's settings and infrastructure, Stripe, and the
order to do it in. Each step points to the detailed page where there is one.

- Portal: `https://portal.properx.org`.
- Site: properx.org, plugin `properx-portal-2.3.0.zip`.
- Secrets are never sent by email or chat. Values marked "client" come from the client.

## The order

| # | Step | Where | Status |
|---|---|---|---|
| 1 | Stripe live account: products, prices, payment methods, restricted key, webhook, Customer Portal | Stripe Dashboard | to do |
| 2 | Infrastructure: request size, WAF | Platform | to do |
| 3 | Merge and deploy the portal release | Azure DevOps | to do |
| 4 | Portal app settings | App Service / Key Vault | to do |
| 5 | Checks on the portal | Container console | to do |
| 6 | Plugin 2.3.0 and the application form on properx.org | WordPress admin | **done** |
| 7 | Feeds of the application form (card and bank payments) | WordPress admin | **done** |
| 8 | Switch on, one thing at a time | Both | to do |
| 9 | Smoke test | Browser | to do |

Steps 1 and 2 can be done before the release; nothing changes for users until step 8.

## 1. Stripe (live account)

Do it in test mode first, then the same in live mode. Exact clicks: [stripe-agency-setup.md](../stripe-agency-setup.md).

1. Product **ProPerX Agency Membership** with the six yearly prices and their lookup keys (founding and standard,
   per tier). Partner-code rates ($149 for 2 to 49 people, $129 for 50 or more) need **no** price of their own: the
   portal sets the amount on the subscription.
2. Product **Credit card surcharge**, metadata `properx_product` = `card_surcharge`, no price.
3. Payment methods: **Cards** and **ACH Direct Debit** on.
4. Restricted key for the portal with the permissions in the table of that page, and the publishable key.
5. Webhook: **one** endpoint `https://portal.properx.org/api/webhooks/stripe`. If it exists already (individual
   members), only make sure these events are ticked: `invoice.paid`, `invoice.payment_failed`,
   `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`. Do not create
   a second endpoint: its secret would differ.
6. Customer Portal: invoices and payment methods on; cancelling, plan and quantity changes off; redirect
   `https://portal.properx.org/member/billing`.

## 2. Infrastructure

1. **Request size.** The license copy of an application arrives as JSON on `POST /api/intake/license-copy`: up to
   about 13.4 MB for a 10 MB file. The ingress or WAF, nginx (`client_max_body_size`) and PHP (`post_max_size`) must
   accept **15 MB** on that path. Too small: the site gets HTTP 413, keeps the file and retries; the member is created
   anyway, without the copy.
2. **WAF.** properx.org's server reaches `/api/intake/*` (already allowed for the intake). The new path is under it.
   `/livewire/*` passes through (agency enrollment), as before.
3. Nothing new for the scheduler or the queue: the platform's `schedule:work` and `queue:work` pick up the new jobs.
   The daily `model:prune` (01:30) now also removes license copies of agency people never created.

## 3. Portal release

1. Merge the PR and let the pipeline deploy it. `migrate --force` runs two additive migrations:
   `2026_11_20_100000_create_pending_license_files_table`,
   `2026_11_21_100000_add_license_document_to_intake_submissions`.
2. In the container:

   ```bash
   php artisan optimize:clear
   php artisan queue:restart
   ```

## 4. Portal app settings

New in this release:

| Variable | Value | Note |
|---|---|---|
| `AGENCY_BILL_EMPLOYEES` | empty (= true) | Agency employees (W2) billed like producers. `false`: they count toward the two-member minimum only. The client's open question |
| `AGENCY_REQUIRE_LICENSE_COPY` | empty (= true) | Licensed producers need a license copy before the agency pays |
| `AGENCY_WELCOME_LOGIN_URL` | empty | "Portal Login Link" in the agency owner's welcome. Empty: the portal's sign-in page |
| `AGENCY_WELCOME_REPLY_TO` | `hello@properx.org` | The owner's welcome asks them to reply with the completed health enrollment application |
| `AGENCY_WELCOME_ATTACHMENT` | empty for now | The health enrollment application attached to that welcome; set in a later release, when the client sends the file. While empty the email goes without it and the log says so |

Changed:

| Variable | Value |
|---|---|
| `CERTIFICATE_SECOND_SIGNATORY_NAME` | `Anne Glorioso` |
| `CERTIFICATE_SECOND_SIGNATORY_TITLE` | `Founder and CEO` |
| `CERTIFICATE_SIGNATURE_IMAGE` | `signature-anne-glorioso.png` (ships in the release) |

Check they are set, live values:

| Variable | Value |
|---|---|
| `APP_URL` | `https://portal.properx.org` |
| `MEMBER_AREA_ENABLED` | `true` |
| `STRIPE_KEY` | `pk_live_…` (step 1) |
| `STRIPE_SECRET` | `rk_live_…`, the restricted key (step 1) |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` of the one endpoint |
| `INTAKE_ENABLED` | `true` |
| `WP_INTAKE_SECRET` | the shared secret, the same as in the plugin ([deploy.md](../wordpress/deploy.md), step 2) |
| `INDIVIDUAL_MEMBERSHIP_URL` | `https://properx.org/apply/` |

Stay off until step 8: `AGENCY_ENROLLMENT_ENABLED`, `AGENCY_HANDOFF_ENABLED`. Unchanged and off:
`INTAKE_SEND_INVITE`, `MEMBERSHIP_EXPIRY_EMAILS_ENABLED`, `RENEWAL_REMINDERS_INDIVIDUAL_ENABLED`,
`STAFF_SUMMARY_ENABLED`. The full list with comments: [portal-env.example](../wordpress/portal-env.example).

Restart (or roll out) after changing settings.

## 5. Checks on the portal

```bash
php artisan app:stripe:agency-prices
```

Every row **OK**, last line `All 6 agency prices and the surcharge product are in Stripe.` Then
`https://portal.properx.org/api/health` answers 200.

## 6 and 7. Plugin, application form and feeds on properx.org: done

The live application form runs on plugin 2.3.0 with the form changes applied, and card and bank payments work.
Nothing to do here. The plugin's **Portal URL** (Forms > Settings > ProPerX Portal) must read
`https://portal.properx.org`; **Test connection** on that page answers "Connected (200)".

## 8. Switch on

In this order, each after the previous one works:

1. **Individual applications with license copies.** Plugin settings: **Send paid applications to the portal** on (if
   not already). A paid application creates the member; the license copy follows as a License Document, pending
   review.
2. **Agency enrollment.** `AGENCY_ENROLLMENT_ENABLED=true`, restart. `/join/agency` opens.
3. **"My agency" on /apply.** `AGENCY_HANDOFF_ENABLED=true`, restart; then plugin settings: **Send agency
   enrollments to the portal** on.

To step back, turn the same switch off: nothing is lost, new applications wait or show the form's own confirmation.

## 9. Smoke test

1. **Individual**, on /apply with a real card of the team: license number, no NPN, a PDF copy. Expected: the
   confirmation; "Welcome to ProPerX Alliance! Your Next Steps" right away; the entry's notes "Sent to portal: OK
   (created; license copy stored in License Documents)"; in Nova the member, license verification "Pending review",
   and the copy under License documents. Then refund, cancel the subscription in Stripe, delete the test user and
   member.
2. **Agency**, on `https://portal.properx.org/join/agency` (or "My agency" on /apply): two people, a card.
   Expected: the confirmation page; the owner gets the welcome (reply-to `hello@properx.org`) and the receipt; the
   other person an invitation; in Stripe a yearly subscription with quantity 2. Then cancel it in Nova ("Cancel
   agency membership", now) and refund in Stripe.
3. **Member area and certificate**: as a member, `https://portal.properx.org/member/certificate`: Anne Glorioso's
   signature bottom right.

## Rollback

- Portal: redeploy the previous release. The two migrations only add a table and a column; the previous release
  ignores them.
- Agency enrollment or the handoff: turn its switch off (step 8).
