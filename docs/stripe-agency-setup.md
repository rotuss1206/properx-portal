# Stripe setup for agency membership

What the Stripe account needs before agencies can enroll and pay in the portal (`/join/agency`). Do every step
in the account the portal will use: in test mode first, then the same in live mode.

The portal finds prices by their **lookup key**, so names and descriptions can be anything; the lookup keys,
amounts, currency and billing period must match exactly.

## 1. Product "ProPerX Agency Membership"

1. Dashboard → **Product catalog** → **+ Create product**.
2. **Name:** `ProPerX Agency Membership`.
3. Optional, recommended: **More options** → **Metadata** → key `properx_product`, value `agency_membership`.
4. Add the six prices below to this product (step 2), then **Add product**.

## 2. The six prices

For each price: **Recurring**, **Billing period: Yearly**, **Pricing model: Per unit** (standard pricing),
**Currency: USD**, the amount below. Open **More pricing options** / **Advanced** to fill **Lookup key**.
The amount is per person per year; the portal sets the quantity to the number of people.

| Price description (suggested) | Amount (USD per year) | Lookup key |
|---|---:|---|
| Agency · Founding | 159.00 | `agency_founding_2to9` |
| Agency · Standard | 179.00 | `agency_standard_2to9` |
| Agency Plus · Founding | 149.00 | `agency_founding_10to49` |
| Agency Plus · Standard | 159.00 | `agency_standard_10to49` |
| Agency Enterprise · Founding | 129.00 | `agency_founding_50plus` |
| Agency Enterprise · Standard | 139.00 | `agency_standard_50plus` |

Tiers: Agency = 2–9 people, Agency Plus = 10–49, Agency Enterprise = 50 or more. Founding rates apply to
payments completed before January 1, 2027, 12:00 a.m. Eastern, and stay with the agency at renewal.

Notes:
- A price cannot be edited once created. To change an amount, create a new price, move the lookup key to it
  (Stripe offers this when the key is already taken) and archive the old one.
- Partner-code rates ($149 or the tier rate, whichever is lower) need no price of their own: the portal bills
  them on the same product with the amount set on the subscription.
- Instead of lookup keys the portal can be given price IDs (`STRIPE_PRICE_AGENCY_FOUNDING_2TO9` …
  `STRIPE_PRICE_AGENCY_STANDARD_50PLUS`). Leave them empty unless there is a reason to pin a specific price.

## 3. Product "Credit card surcharge"

Agencies paying with a **credit** card (not debit, prepaid or bank account) pay a 2.9% surcharge on the dues.
The portal adds it as a separate one-time line, "Credit card surcharge", on the first invoice of the
subscription, with the amount it calculated. The line needs a product to belong to; the product has no price.

1. **Product catalog** → **+ Create product**.
2. **Name:** `Credit card surcharge`.
3. **More options** → **Metadata** → key `properx_product`, value `card_surcharge` (**required**: the portal
   finds this product by that metadata).
4. Do not add a price. Save.

## 4. Payment methods

**Settings** → **Payments** → **Payment methods** (the default configuration):

- **Cards**: on.
- **ACH Direct Debit** (US bank account): on. Instant verification through Financial Connections should stay
  on; bank payments take a few business days to clear, and the portal finishes the enrollment when Stripe
  reports the invoice paid.

The payment form offers exactly these two (`card`, `us_bank_account`).

## 5. API keys for the portal

**Developers** → **API keys**.

- **Publishable key** (`pk_…`) → `STRIPE_KEY`.
- **Restricted key** (`rk_…`) → `STRIPE_SECRET`. **+ Create restricted key**, name it e.g. "ProPerX portal",
  and give only these permissions (everything else: **None**):

| Resource | Permission | Used for |
|---|---|---|
| Customers | Write | creating the agency's customer at checkout |
| Subscriptions | Write | creating the yearly subscription (with the surcharge line), cancelling an unpaid one left behind when the order changes, reading a subscription |
| Invoices | Read | reading the subscription's first invoice to confirm the payment |
| PaymentIntents | Read | checking the payment after the browser confirms it |
| ConfirmationTokens | Read | reading the chosen payment method before paying (credit card or not, for the surcharge) |
| Prices | Read | finding the six prices by lookup key |
| Products | Read | finding the two products by metadata |

Prices and Products **Write** are needed only for `php artisan app:stripe:agency-prices --create`, which works
with test-mode keys only and is not used in live mode.

## 6. Webhook

**Developers** → **Webhooks** → **+ Add endpoint** (in newer dashboards: **Workbench** → **Webhooks** →
**Create an event destination**, type **Webhook endpoint**, your account).

- **Endpoint URL:** `https://<portal host>/api/webhooks/stripe`
- **API version:** `2026-09-30.endive` (the version of the portal's Stripe library; the portal reads fields
  that older versions place elsewhere).
- **Events:**
  - `invoice.paid`
  - `invoice.payment_failed`
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
- After saving, reveal the **Signing secret** (`whsec_…`) → `STRIPE_WEBHOOK_SECRET`.

If an endpoint for individual members already points to this URL, add the missing events to it instead of
creating a second one.

## 7. Portal settings

| Variable | Value |
|---|---|
| `STRIPE_KEY` | publishable key |
| `STRIPE_SECRET` | restricted key |
| `STRIPE_WEBHOOK_SECRET` | signing secret of the endpoint |
| `PAYMENT_PORTAL_URL` | page where an agency can pay an overdue invoice when Stripe gives no invoice link (optional) |
| `AGENCY_ENROLLMENT_ENABLED` | `true` once the steps above are done |

## 8. Check

On the portal (after deployment, and after any change in Stripe):

```bash
php artisan app:stripe:agency-prices
```

Every row must say **OK** and the last line must read
`All 6 agency prices and the surcharge product are in Stripe.` (exit code 0). Otherwise the row says what is
wrong: no active price with the lookup key, a wrong amount, currency or billing period, or no surcharge product.

Then send a test event from the webhook's page in the Dashboard (**Send test event** → `invoice.paid`): the
endpoint must answer **200**.
