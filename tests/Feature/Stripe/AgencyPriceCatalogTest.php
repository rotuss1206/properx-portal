<?php

namespace Tests\Feature\Stripe;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Services\Stripe\AgencyPriceCatalog;
use App\Services\Stripe\MissingStripePriceException;
use RuntimeException;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The six agency prices in Stripe, through the fake HTTP client (Tests\Support\FakeStripe).
 */
class AgencyPriceCatalogTest extends TestCase
{
    private const AMOUNTS = [
        'agency_founding_2to9' => 15900, 'agency_standard_2to9' => 17900,
        'agency_founding_10to49' => 14900, 'agency_standard_10to49' => 15900,
        'agency_founding_50plus' => 12900, 'agency_standard_50plus' => 13900,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(string $key, ?int $amount = null, string $interval = 'year', string $currency = 'usd'): array
    {
        return [
            'id' => 'price_'.$key, 'object' => 'price', 'active' => true, 'currency' => $currency, 'lookup_key' => $key,
            'product' => 'prod_agency', 'recurring' => ['interval' => $interval, 'interval_count' => 1],
            'unit_amount' => $amount ?? self::AMOUNTS[$key],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     */
    private function stripeHas(array $prices): void
    {
        $this->stripe->on('GET', '/v1/prices', fn () => ['object' => 'list', 'data' => $prices, 'has_more' => false, 'url' => '/v1/prices']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function allPrices(): array
    {
        return array_map(fn (string $key) => self::price($key), array_keys(self::AMOUNTS));
    }

    public function test_prices_are_found_by_their_lookup_keys_and_cached(): void
    {
        $this->stripeHas(self::allPrices());
        $catalog = app(AgencyPriceCatalog::class);

        $this->assertSame('price_agency_founding_10to49', $catalog->priceId(AgencyTier::AgencyPlus, RateType::Founding));
        $this->assertSame('price_agency_standard_50plus', $catalog->priceId(AgencyTier::AgencyEnterprise, RateType::Standard));

        $requests = $this->stripe->sent('GET', '/v1/prices');
        $this->assertCount(1, $requests);
        $this->assertEqualsCanonicalizing(array_keys(self::AMOUNTS), $requests[0]['params']['lookup_keys']);
        $this->assertSame('true', $requests[0]['params']['active']); // Stripe sends booleans as words.
    }

    public function test_a_configured_price_id_is_used_without_asking_stripe(): void
    {
        config(['agency.tiers.agency_2to9.stripe_prices.founding.price_id' => 'price_pinned']);

        $this->assertSame('price_pinned', app(AgencyPriceCatalog::class)->priceId(AgencyTier::Agency, RateType::Founding));
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_missing_price_is_asked_again_then_named_in_a_clear_error(): void
    {
        $this->stripeHas([self::price('agency_founding_2to9')]);

        try {
            app(AgencyPriceCatalog::class)->priceId(AgencyTier::AgencyPlus, RateType::Standard);
            $this->fail('A missing price must not pass.');
        } catch (MissingStripePriceException $e) {
            $this->assertStringContainsString('lookup key "agency_standard_10to49"', $e->getMessage());
            $this->assertStringContainsString('app:stripe:agency-prices --create', $e->getMessage());
            $this->assertStringContainsString('STRIPE_PRICE_AGENCY_STANDARD_10TO49', $e->getMessage());
        }

        // The cached list did not have it, so Stripe was asked once more.
        $this->assertCount(2, $this->stripe->sent('GET', '/v1/prices'));
    }

    public function test_stripe_refusing_the_key_is_reported_and_not_cached(): void
    {
        $this->stripe->on('GET', '/v1/prices', ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);

        try {
            app(AgencyPriceCatalog::class)->byLookupKey();
            $this->fail('An authentication error must not pass.');
        } catch (MissingStripePriceException $e) {
            $this->assertStringContainsString('Stripe could not be asked for the agency prices (Stripe\Exception\AuthenticationException)', $e->getMessage());
        }

        $this->expectException(MissingStripePriceException::class);
        app(AgencyPriceCatalog::class)->byLookupKey();
    }

    public function test_tests_never_reach_stripe(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A test sent an unexpected Stripe request: get /v1/customers/cus_1');

        (new StripeClient('sk_test_fake'))->customers->retrieve('cus_1');
    }

    // app:stripe:agency-prices

    public function test_the_check_passes_when_all_six_prices_are_right(): void
    {
        $this->stripeHas(self::allPrices());

        $this->artisan('app:stripe:agency-prices')
            ->expectsOutputToContain('All 6 agency prices are in Stripe.')
            ->assertSuccessful();

        $this->assertSame([], $this->stripe->sent('POST', '/v1/prices'));
    }

    public function test_the_check_names_missing_and_wrong_prices(): void
    {
        $prices = self::allPrices();
        unset($prices[0]);
        $prices[1] = self::price('agency_standard_2to9', 19900);
        $prices[2] = self::price('agency_founding_10to49', interval: 'month');
        $this->stripeHas(array_values($prices));

        $this->artisan('app:stripe:agency-prices')
            ->expectsTable(['Lookup key', 'Rate', 'Price', 'Result'], [
                ['agency_founding_2to9', '$159.00 a year', '—', 'No active price with this lookup key.'],
                ['agency_standard_2to9', '$179.00 a year', 'price_agency_standard_2to9', 'The price is $199.00; it should be $179.00.'],
                ['agency_founding_10to49', '$149.00 a year', 'price_agency_founding_10to49', 'It is not billed yearly.'],
                ['agency_standard_10to49', '$159.00 a year', 'price_agency_standard_10to49', 'OK'],
                ['agency_founding_50plus', '$129.00 a year', 'price_agency_founding_50plus', 'OK'],
                ['agency_standard_50plus', '$139.00 a year', 'price_agency_standard_50plus', 'OK'],
            ])
            ->expectsOutputToContain('3 of 6 agency prices need attention.')
            ->assertFailed();
    }

    public function test_the_check_needs_a_key(): void
    {
        config(['services.stripe.secret' => null]);

        $this->artisan('app:stripe:agency-prices')->expectsOutputToContain('STRIPE_SECRET is empty')->assertFailed();
    }

    public function test_create_makes_the_product_and_the_missing_prices_once(): void
    {
        $created = [];
        $this->stripe
            ->on('GET', '/v1/prices', function () use (&$created) {
                return ['object' => 'list', 'data' => $created, 'has_more' => false, 'url' => '/v1/prices'];
            })
            ->on('GET', '/v1/products', ['object' => 'list', 'data' => [['id' => 'prod_other', 'object' => 'product', 'metadata' => []]], 'has_more' => false, 'url' => '/v1/products'])
            ->on('POST', '/v1/products', fn (array $params) => ['id' => 'prod_agency', 'object' => 'product', 'name' => $params['name'], 'metadata' => $params['metadata']])
            ->on('POST', '/v1/prices', function (array $params) use (&$created) {
                $price = self::price($params['lookup_key'], (int) $params['unit_amount'], $params['recurring']['interval'], $params['currency']);
                $created[] = $price;

                return $price;
            });

        $this->artisan('app:stripe:agency-prices', ['--create' => true])
            ->expectsOutputToContain('Created the product prod_agency.')
            ->expectsOutputToContain('Created agency_founding_2to9: price_agency_founding_2to9')
            ->expectsOutputToContain('All 6 agency prices are in Stripe.')
            ->assertSuccessful();

        $product = $this->stripe->sent('POST', '/v1/products');
        $this->assertCount(1, $product);
        $this->assertSame(['name' => 'ProPerX Agency Membership', 'metadata' => ['properx_product' => 'agency_membership']], $product[0]['params']);

        $prices = $this->stripe->sent('POST', '/v1/prices');
        $this->assertCount(6, $prices);
        $this->assertSame([
            'product' => 'prod_agency', 'currency' => 'usd', 'unit_amount' => 14900, 'recurring' => ['interval' => 'year'],
            'lookup_key' => 'agency_founding_10to49', 'transfer_lookup_key' => 'true', 'nickname' => 'Agency Plus · Founding',
            'metadata' => ['properx_tier' => 'agency_10to49', 'properx_rate_type' => 'founding'],
        ], $prices[2]['params']);

        // Again: nothing new.
        $this->artisan('app:stripe:agency-prices', ['--create' => true])
            ->expectsOutputToContain('Nothing to create')
            ->assertSuccessful();

        $this->assertCount(6, $this->stripe->sent('POST', '/v1/prices'));
        $this->assertCount(1, $this->stripe->sent('POST', '/v1/products'));
    }

    public function test_create_adds_only_what_is_missing_to_the_existing_product(): void
    {
        $prices = self::allPrices();
        array_pop($prices);
        $this->stripeHas($prices);
        $this->stripe->on('POST', '/v1/prices', fn (array $params) => self::price($params['lookup_key']));

        $this->artisan('app:stripe:agency-prices', ['--create' => true])
            ->expectsOutputToContain('Created agency_standard_50plus')
            ->run();

        $this->assertSame([], $this->stripe->sent('GET', '/v1/products'));
        $this->assertSame('prod_agency', $this->stripe->sent('POST', '/v1/prices')[0]['params']['product']);
        $this->assertCount(1, $this->stripe->sent('POST', '/v1/prices'));
    }

    public function test_create_refuses_live_keys_and_production(): void
    {
        config(['services.stripe.secret' => 'sk_live_fake']);

        $this->artisan('app:stripe:agency-prices', ['--create' => true])
            ->expectsOutputToContain('--create works only with a test-mode key')
            ->assertFailed();

        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('app:stripe:agency-prices', ['--create' => true])
            ->expectsOutputToContain('--create works only with a test-mode key')
            ->assertFailed();

        $this->assertSame([], $this->stripe->requests);
    }
}
