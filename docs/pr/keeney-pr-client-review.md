# Client review: certificate signature, benefits links, test data clean-up

## Summary

- **Certificate.** Only Anne Glorioso signs (her signature image, Founder and CEO). The Executive Director's
  account no longer signs by itself. The first signatory is optional: `CERTIFICATE_FIRST_SIGNATORY_NAME` /
  `_TITLE`, empty in production. With one signature the row is: date issued, seal, signature. Under the signature
  there is a line, the name and the title in the preview, the PNG and the PDF. `TEMPLATE_VERSION` is 14, so cached
  PDFs are made again.
- **Member dashboard, Benefits tab.** Four cards: group health, dental, vision, and pet insurance (provided by
  PetPartners). Each card has **Enroll**, which opens my-benefits.net in a new tab. **Choose a Plan** appears once
  its address is configured. "CRM for agents" and "Business discounts" are hidden in config. These are links only:
  nothing is embedded, and the CSP is unchanged.
- **`php artisan app:purge-test-data`.**
  - Removes listed test accounts and everything that belongs to them: profiles, license and tax documents with their
    stored files, certificate caches, invitations, enrollment drafts, intake submissions, local Stripe event records
    and notifications. An agency goes when its principal is selected and nobody else on it stays.
  - Selects only `--email`, `--domain` (member accounts; staff only by exact email), `--file` and `--demo` (the
    demo seeders' accounts, printed in full).
  - A dry run unless `--force`. Never touches the API Service account or the last Executive Director.
  - No Stripe calls. One audit record (`test_data_purged`) with counts only.
- **Stripe Link off** in the Payment Element on `/join/agency` (`wallets.link: 'never'`), as on properx.org.
- **Livewire paths (PR 324) kept.** `AppServiceProvider` and `docs/PORTAL.md` carry Kevin's fixed
  `/livewire/livewire.min.js` and `/livewire/update` together with this release's changes. His
  `LivewireEndpointsTest` is included with one correction: Livewire still prints its hashed prefix as
  `data-module-url` (the base for component JS modules, which no component here uses). The test now checks the
  script `src` and `data-update-uri` instead of the whole page. Checked locally with routes cached under a
  different `APP_KEY`: the script answers 200 and the update route exists.

## Migrations

None.

## Environment

New, all empty in production:

| Variable | Meaning |
|---|---|
| `CERTIFICATE_FIRST_SIGNATORY_NAME`, `_TITLE` | Optional first signature. Empty: none |
| `BENEFITS_HEALTH_ENROLL_URL`, `BENEFITS_PET_ENROLL_URL` | Enroll links. Empty: the my-benefits.net forms |
| `BENEFITS_HEALTH_PLAN_URL`, `_DENTAL_`, `_VISION_`, `_PET_PLAN_URL` | Choose a Plan. Empty: no button |

Unchanged: `CERTIFICATE_SECOND_SIGNATORY_NAME` / `_TITLE` / `CERTIFICATE_SIGNATURE_IMAGE` (Anne Glorioso).

## Deploy

1. `php artisan optimize:clear`
2. `php artisan queue:restart`

## Test data clean-up, after deploy

```bash
php artisan app:purge-test-data --email=tester@example.com --domain=test-agency.com          # dry run
php artisan app:purge-test-data --email=tester@example.com --domain=test-agency.com --force  # remove
```

Read the dry run's account list before adding `--force`. Test customers and subscriptions in Stripe test mode are
removed in the Stripe Dashboard; this command does not call Stripe.

## Tests

1123 pass, 1 skipped (Azurite integration, needs the emulator). Pint clean.
