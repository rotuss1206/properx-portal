<?php

namespace App\Services\Agencies;

use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 * The agency payment could not go ahead; the message is written for the person paying.
 */
final class CheckoutException extends RuntimeException
{
    public const GENERIC = 'The payment could not be started. Please try again in a few minutes.';

    public static function fromStripe(ApiErrorException $e): self
    {
        return new self($e instanceof CardException ? self::forCode((string) $e->getStripeCode(), (string) $e->getDeclineCode()) : self::GENERIC, previous: $e);
    }

    /**
     * Words for Stripe's error codes, as Stripe.js or the API report them. Nothing from the
     * browser is shown as it came: an unknown code gets the general sentence.
     */
    public static function forCode(string $code, string $declineCode = ''): string
    {
        return match (true) {
            $code === 'payment_intent_authentication_failure' => "Your card's verification was not completed, so nothing was charged. Please try again, or use another card or a bank account.",
            in_array($declineCode, ['insufficient_funds'], true) => 'Your card was declined for insufficient funds. Nothing was charged. Please use another card or a bank account.',
            $code === 'expired_card' || $declineCode === 'expired_card' => 'Your card has expired. Nothing was charged. Please use another card.',
            $code === 'incorrect_cvc' || $declineCode === 'incorrect_cvc' => "The card's security code is incorrect. Nothing was charged. Please check it and try again.",
            $code === 'card_declined' || $declineCode !== '' => 'Your card was declined, so nothing was charged. Please use another card or a bank account, or ask your bank.',
            $code === 'processing_error' => 'Something went wrong while processing the card. Nothing was charged. Please try again.',
            default => 'The payment did not go through, so nothing was charged. Please try again, or use another payment method.',
        };
    }
}
