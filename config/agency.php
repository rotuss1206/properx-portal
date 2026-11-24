<?php

/*
|--------------------------------------------------------------------------
| Agency membership pricing
|--------------------------------------------------------------------------
|
| Annual dues per person (the principal counts as one); amounts are in cents.
| One Stripe product, "ProPerX Agency Membership", with six annual recurring
| prices; the subscription quantity equals the headcount. Prices are found by
| their lookup key unless a price ID is configured.
|
*/

return [

    // An agency always pays for at least this many members (the client's rule): a principal alone pays
    // for two. Billable members = max(this, people billed); see AgencyPricingService::billableSeats().
    'minimum_headcount' => 2,

    // Whether agency employees (W2, no license) are billed like licensed producers. false: they count
    // toward the minimum only, and the agency pays for its producers (at least the minimum). Waiting for
    // the client's answer; true is the behaviour so far.
    'bill_employees' => (bool) env('AGENCY_BILL_EMPLOYEES', true),

    // Licensed producers need a copy of their license (PDF, JPG or PNG) before the agency can pay
    // (wizard and "Add people"). Relaxed by setting AGENCY_REQUIRE_LICENSE_COPY=false.
    'require_license_copy' => (bool) env('AGENCY_REQUIRE_LICENSE_COPY', true),

    // The Stripe product the six prices belong to (created in a test account by
    // `php artisan app:stripe:agency-prices --create`), and their currency.
    'stripe_product_name' => 'ProPerX Agency Membership',
    'currency' => 'usd',

    // How long the price IDs found by lookup key are kept in the cache, in seconds.
    'stripe_price_cache_seconds' => 3600,

    // Founding rates for payments completed before 12:00 a.m. Eastern on January 1, 2027.
    // They stay locked at every renewal while the agency remains a member without a break.
    'founding_cutoff' => '2027-01-01 00:00:00',
    'founding_cutoff_timezone' => 'America/New_York',

    // With a valid MGA/FMO partner code (client's rule): Agency and Agency Plus (2-49) are one tier at
    // $149, Agency Enterprise (50+) is $129. The lower of this and the tier rate applies (no stacking).
    'partner_rates' => [
        'agency_2to9' => 14900,
        'agency_10to49' => 14900,
        'agency_50plus' => 12900,
    ],

    // Credit cards only (card.funding = "credit"); not debit, prepaid, unknown or ACH.
    'credit_card_surcharge_basis_points' => 290,

    // States where no surcharge is added, whatever the card: two-letter codes of the paying
    // agency's legal address (screen 3), comma-separated, e.g. "CT,MA". Empty: none.
    'surcharge_exempt_states' => array_values(array_filter(array_map(
        fn (string $state) => strtoupper(trim($state)),
        explode(',', (string) env('AGENCY_SURCHARGE_EXEMPT_STATES', '')),
    ))),

    'tiers' => [
        'agency_2to9' => [
            'min_headcount' => 2,
            'max_headcount' => 9,
            'rates' => ['founding' => 15900, 'standard' => 17900],
            'stripe_prices' => [
                'founding' => ['lookup_key' => 'agency_founding_2to9', 'price_id' => env('STRIPE_PRICE_AGENCY_FOUNDING_2TO9')],
                'standard' => ['lookup_key' => 'agency_standard_2to9', 'price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_2TO9')],
            ],
        ],
        'agency_10to49' => [
            'min_headcount' => 10,
            'max_headcount' => 49,
            'rates' => ['founding' => 14900, 'standard' => 15900],
            'stripe_prices' => [
                'founding' => ['lookup_key' => 'agency_founding_10to49', 'price_id' => env('STRIPE_PRICE_AGENCY_FOUNDING_10TO49')],
                'standard' => ['lookup_key' => 'agency_standard_10to49', 'price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_10TO49')],
            ],
        ],
        'agency_50plus' => [
            'min_headcount' => 50,
            'max_headcount' => null,
            'rates' => ['founding' => 12900, 'standard' => 13900],
            'stripe_prices' => [
                'founding' => ['lookup_key' => 'agency_founding_50plus', 'price_id' => env('STRIPE_PRICE_AGENCY_FOUNDING_50PLUS')],
                'standard' => ['lookup_key' => 'agency_standard_50plus', 'price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_50PLUS')],
            ],
        ],
    ],

];
