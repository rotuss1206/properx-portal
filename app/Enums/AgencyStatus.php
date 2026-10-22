<?php

namespace App\Enums;

/**
 * Billing state of an agency membership.
 */
enum AgencyStatus: string
{
    /** Enrolled, first payment not completed yet. */
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    /** Canceled, or not renewed after the reminder period: every person becomes inactive. */
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending payment',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Inactive => 'Inactive',
        };
    }
}
