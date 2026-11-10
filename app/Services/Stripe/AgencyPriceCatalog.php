<?php

namespace App\Services\Stripe;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\StripeClient;

/**
 * The six agency prices in Stripe (Agency spec 4): one per tier and rate type, found by the
 * lookup keys in config/agency.php unless a price ID is configured (STRIPE_PRICE_AGENCY_*).
 * Lookups are cached per account; a key not found is asked again before it counts as missing.
 * Reads only; `app:stripe:agency-prices --create` is the one place that writes, in test mode.
 */
class AgencyPriceCatalog
{
    private const CACHE_PREFIX = 'stripe:agency-prices:';

    public static function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Every price the portal needs, with the amount it must have (cents, per person per year).
     *
     * @return list<array{tier: AgencyTier, rateType: RateType, lookupKey: string, priceId: ?string, amount: int}>
     */
    public static function expected(): array
    {
        $prices = [];

        foreach (AgencyTier::cases() as $tier) {
            foreach (RateType::cases() as $rateType) {
                $config = $tier->stripePrice($rateType);

                $prices[] = [
                    'tier' => $tier,
                    'rateType' => $rateType,
                    'lookupKey' => $config['lookup_key'],
                    'priceId' => filled($config['price_id'] ?? null) ? (string) $config['price_id'] : null,
                    'amount' => $tier->rate($rateType),
                ];
            }
        }

        return $prices;
    }

    /**
     * The environment variable that pins a price ID instead of the lookup key.
     */
    public static function envName(string $lookupKey): string
    {
        return 'STRIPE_PRICE_'.strtoupper($lookupKey);
    }

    /**
     * @throws MissingStripePriceException
     */
    public function priceId(AgencyTier $tier, RateType $rateType): string
    {
        $config = $tier->stripePrice($rateType);

        if (filled($config['price_id'] ?? null)) {
            return (string) $config['price_id'];
        }

        $key = $config['lookup_key'];
        $prices = $this->byLookupKey();

        // Created since the list was cached: ask once more before giving up.
        if (! isset($prices[$key])) {
            $prices = $this->byLookupKey(fresh: true);
        }

        return $prices[$key]['id'] ?? throw MissingStripePriceException::forLookupKey($key);
    }

    /**
     * The active prices holding the agency lookup keys, by key.
     *
     * @return array<string, array{id: string, product: string, amount: ?int, currency: string, interval: ?string}>
     *
     * @throws MissingStripePriceException when Stripe cannot be asked
     */
    public function byLookupKey(bool $fresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX.substr(hash('sha256', (string) config('services.stripe.secret')), 0, 16);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, (int) config('agency.stripe_price_cache_seconds'), function () {
            try {
                $list = $this->client()->prices->all([
                    'lookup_keys' => array_column(self::expected(), 'lookupKey'),
                    'active' => true,
                    'limit' => 100,
                ]);
            } catch (ApiErrorException $e) {
                throw MissingStripePriceException::unreachable($e::class);
            }

            $prices = [];

            foreach ($list->data as $price) {
                $prices[(string) $price->lookup_key] = self::describe($price);
            }

            return $prices;
        });
    }

    /**
     * What is wrong with each price, by lookup key; empty when all six are usable.
     *
     * @return array<string, string>
     *
     * @throws MissingStripePriceException when Stripe cannot be asked
     */
    public function problems(): array
    {
        $found = $this->byLookupKey(fresh: true);
        $currency = (string) config('agency.currency');
        $problems = [];

        foreach (self::expected() as $expected) {
            $key = $expected['lookupKey'];

            if ($expected['priceId'] !== null) {
                try {
                    $price = $this->client()->prices->retrieve($expected['priceId']);
                } catch (ApiErrorException) {
                    $problems[$key] = self::envName($key).' names a price that is not in this Stripe account.';

                    continue;
                }

                if (! $price->active) {
                    $problems[$key] = self::envName($key).' names an archived price.';

                    continue;
                }

                $actual = self::describe($price);
            } elseif (! isset($found[$key])) {
                $problems[$key] = 'No active price with this lookup key.';

                continue;
            } else {
                $actual = $found[$key];
            }

            $problem = match (true) {
                $actual['amount'] !== $expected['amount'] => sprintf('The price is %s; it should be %s.', self::money($actual['amount']), self::money($expected['amount'])),
                $actual['currency'] !== $currency => "The currency is {$actual['currency']}; it should be {$currency}.",
                $actual['interval'] !== 'year' => 'It is not billed yearly.',
                default => null,
            };

            if ($problem !== null) {
                $problems[$key] = $problem;
            }
        }

        return $problems;
    }

    public function client(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }

    /**
     * @return array{id: string, product: string, amount: ?int, currency: string, interval: ?string}
     */
    private static function describe(Price $price): array
    {
        return [
            'id' => (string) $price->id,
            'product' => is_string($price->product) ? $price->product : (string) ($price->product->id ?? ''),
            'amount' => $price->unit_amount === null ? null : (int) $price->unit_amount,
            'currency' => (string) $price->currency,
            'interval' => $price->recurring?->interval,
        ];
    }

    private static function money(?int $cents): string
    {
        return $cents === null ? 'not a fixed amount' : '$'.number_format($cents / 100, 2);
    }
}
