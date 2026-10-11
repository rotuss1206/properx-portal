<?php

namespace App\Enums;

/**
 * Membership dues status mirrored from Stripe (Spec §7).
 */
enum PaymentStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    /** No information from Stripe yet; treated as paid so nobody is locked out by default. */
    case Unknown = 'unknown';

    /**
     * Map a Stripe subscription status to the portal status.
     */
    public static function fromSubscriptionStatus(string $status): self
    {
        return match ($status) {
            'active', 'trialing' => self::Active,
            'past_due', 'unpaid', 'incomplete' => self::PastDue,
            'canceled', 'incomplete_expired', 'paused' => self::Canceled,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
            self::Unknown => 'Unknown',
        };
    }
}
