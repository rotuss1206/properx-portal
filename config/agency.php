<?php

/*
|--------------------------------------------------------------------------
| Agency membership pricing
|--------------------------------------------------------------------------
|
| Annual dues per person (the principal counts as one); amounts are in cents.
| One Stripe product, "ProPerx Agency Membership", with six annual recurring
| prices; the subscription quantity equals the headcount. Prices are found by
| their lookup key unless a price ID is configured.
|
*/

return [

    'minimum_headcount' => 2,

    // Founding rates for payments completed before 12:00 a.m. Eastern on January 1, 2027.
    // They stay locked at every renewal while the agency remains a member without a break.
    'founding_cutoff' => '2027-01-01 00:00:00',
    'founding_cutoff_timezone' => 'America/New_York',

    // With a valid MGA/FMO partner code the rate is the lower of this and the tier rate.
    'partner_rate' => 14900,

    // Credit cards only (card.funding = "credit"); not debit, prepaid, unknown or ACH.
    'credit_card_surcharge_basis_points' => 290,

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
