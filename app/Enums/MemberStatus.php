<?php

namespace App\Enums;

/**
 * Drives access when agency billing lapses (separate from the individual Stripe payment status).
 */
enum MemberStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
