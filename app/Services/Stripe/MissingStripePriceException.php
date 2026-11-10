<?php

namespace App\Services\Stripe;

use RuntimeException;

/**
 * An agency price the portal needs is not in the Stripe account (or Stripe could not be asked).
 * The message says what to do; it holds no customer data.
 */
final class MissingStripePriceException extends RuntimeException
{
    public static function forLookupKey(string $lookupKey): self
    {
        return new self(sprintf(
            'Stripe has no active price with the lookup key "%s". Create it in the Stripe account (in a test account: php artisan app:stripe:agency-prices --create), or set %s to its price ID.',
            $lookupKey,
            AgencyPriceCatalog::envName($lookupKey),
        ));
    }

    public static function unreachable(string $errorClass): self
    {
        return new self("Stripe could not be asked for the agency prices ({$errorClass}). Check STRIPE_SECRET and the connection, then try again.");
    }
}
