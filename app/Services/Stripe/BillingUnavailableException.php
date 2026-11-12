<?php

namespace App\Services\Stripe;

use RuntimeException;

/**
 * Stripe did not answer the billing page (or refused): the page says so in its own words.
 */
final class BillingUnavailableException extends RuntimeException
{
    public const MESSAGE = 'We can’t load your billing details from Stripe right now. Please try again in a few minutes.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
