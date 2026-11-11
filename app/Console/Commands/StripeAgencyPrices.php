<?php

namespace App\Console\Commands;

use App\Services\Stripe\AgencyPriceCatalog;
use App\Services\Stripe\MissingStripePriceException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Checks that the Stripe account behind STRIPE_SECRET has the six agency prices (Agency spec 4),
 * and with --create makes the product and whatever prices are missing, but only with a test-mode
 * key and never in production: live prices are the account owner's. Run again, it creates nothing new.
 */
class StripeAgencyPrices extends Command
{
    protected $signature = 'app:stripe:agency-prices
                            {--create : Create the product and any missing prices (test-mode keys only)}';

    protected $description = 'Check the six agency membership prices in Stripe, or create them in a test account';

    private const PRODUCT_METADATA = ['properx_product' => 'agency_membership'];

    public function handle(AgencyPriceCatalog $catalog): int
    {
        if (! AgencyPriceCatalog::isConfigured()) {
            $this->error('STRIPE_SECRET is empty, so Stripe cannot be checked.');

            return self::FAILURE;
        }

        if ($this->option('create')) {
            $secret = (string) config('services.stripe.secret');

            if (app()->isProduction() || ! (Str::startsWith($secret, ['sk_test_', 'rk_test_']))) {
                $this->error('--create works only with a test-mode key (sk_test_… or rk_test_…) outside production. Live prices are created by the account owner.');

                return self::FAILURE;
            }

            try {
                $this->create($catalog);
            } catch (ApiErrorException $e) {
                $this->error('Stripe refused the request ('.$e::class.'): '.$e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $found = $catalog->byLookupKey(fresh: true);
            $problems = $catalog->problems();
        } catch (MissingStripePriceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Lookup key', 'Rate', 'Price', 'Result'], [
            ...array_map(fn (array $expected) => [
                $expected['lookupKey'],
                '$'.number_format($expected['amount'] / 100, 2).' a year',
                $expected['priceId'] ?? ($found[$expected['lookupKey']]['id'] ?? '—'),
                $problems[$expected['lookupKey']] ?? 'OK',
            ], AgencyPriceCatalog::expected()),
            [
                'Credit card surcharge',
                ((int) config('agency.credit_card_surcharge_basis_points') / 100).'% of the dues',
                $catalog->products()[AgencyPriceCatalog::SURCHARGE_PRODUCT] ?? '—',
                $problems[AgencyPriceCatalog::SURCHARGE_PRODUCT] ?? 'OK',
            ],
        ]);

        if ($problems !== []) {
            $this->error(count($problems).' of 7 agency items need attention.');

            return self::FAILURE;
        }

        $this->info('All 6 agency prices and the surcharge product are in Stripe.');

        return self::SUCCESS;
    }

    /**
     * The product (found by metadata, or through a price already holding one of the keys) and the
     * prices missing from it. A price with the wrong amount is left as it is and reported:
     * Stripe prices cannot be changed, and replacing one is the account owner's decision.
     */
    private function create(AgencyPriceCatalog $catalog): void
    {
        $stripe = $catalog->client();
        $found = $catalog->byLookupKey(fresh: true);
        $missing = array_filter(AgencyPriceCatalog::expected(), fn (array $expected) => $expected['priceId'] === null && ! isset($found[$expected['lookupKey']]));

        // The first invoice's "Credit card surcharge" line needs a product of its own.
        if (! isset($catalog->products(fresh: true)[AgencyPriceCatalog::SURCHARGE_PRODUCT])) {
            $product = $stripe->products->create(['name' => 'Credit card surcharge', 'metadata' => ['properx_product' => AgencyPriceCatalog::SURCHARGE_PRODUCT]]);
            $this->line("Created the surcharge product {$product->id}.");
            $catalog->products(fresh: true);
        }

        if ($missing === []) {
            $this->line('Nothing to create: every agency price already exists.');

            return;
        }

        $productId = collect($found)->pluck('product')->filter()->first() ?? $this->product($stripe);

        foreach ($missing as $expected) {
            $price = $stripe->prices->create([
                'product' => $productId,
                'currency' => config('agency.currency'),
                'unit_amount' => $expected['amount'],
                'recurring' => ['interval' => 'year'],
                'lookup_key' => $expected['lookupKey'],
                // Takes the key over from an archived price that still holds it.
                'transfer_lookup_key' => true,
                'nickname' => $expected['tier']->label().' · '.$expected['rateType']->label(),
                'metadata' => ['properx_tier' => $expected['tier']->value, 'properx_rate_type' => $expected['rateType']->value],
            ]);

            $this->line("Created {$expected['lookupKey']}: {$price->id}");
        }

        $catalog->byLookupKey(fresh: true);
    }

    private function product(StripeClient $stripe): string
    {
        foreach ($stripe->products->all(['active' => true, 'limit' => 100])->autoPagingIterator() as $product) {
            if (($product->metadata['properx_product'] ?? null) === self::PRODUCT_METADATA['properx_product']) {
                $this->line("Using the product {$product->id}.");

                return (string) $product->id;
            }
        }

        $product = $stripe->products->create(['name' => config('agency.stripe_product_name'), 'metadata' => self::PRODUCT_METADATA]);
        $this->line("Created the product {$product->id}.");

        return (string) $product->id;
    }
}
