<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Agency pricing tier, chosen by headcount (the principal counts as one).
 * Headcount ranges, rates and Stripe prices live in config/agency.php.
 */
enum AgencyTier: string
{
    case Agency = 'agency_2to9';
    case AgencyPlus = 'agency_10to49';
    case AgencyEnterprise = 'agency_50plus';

    public static function forHeadcount(int $headcount): self
    {
        foreach (self::cases() as $tier) {
            $max = $tier->maxHeadcount();

            if ($headcount >= $tier->minHeadcount() && ($max === null || $headcount <= $max)) {
                return $tier;
            }
        }

        throw new InvalidArgumentException("No agency tier covers a headcount of {$headcount}.");
    }

    public function minHeadcount(): int
    {
        return (int) $this->config('min_headcount');
    }

    public function maxHeadcount(): ?int
    {
        $max = $this->config('max_headcount');

        return $max === null ? null : (int) $max;
    }

    /**
     * Annual rate per person, in cents.
     */
    public function rate(RateType $rateType): int
    {
        return (int) $this->config("rates.{$rateType->value}");
    }

    /**
     * @return array{lookup_key: string, price_id: ?string}
     */
    public function stripePrice(RateType $rateType): array
    {
        return $this->config("stripe_prices.{$rateType->value}");
    }

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::AgencyPlus => 'Agency Plus',
            self::AgencyEnterprise => 'Agency Enterprise',
        };
    }

    private function config(string $key): mixed
    {
        return config("agency.tiers.{$this->value}.{$key}");
    }
}
