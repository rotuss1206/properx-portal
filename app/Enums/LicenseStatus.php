<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum LicenseStatus: string
{
    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';

    /**
     * Days before expiry when a license is considered "expiring".
     */
    public const EXPIRING_WINDOW_DAYS = 60;

    public static function fromExpiryDate(?CarbonInterface $expiresAt, ?CarbonInterface $today = null): self
    {
        $today = ($today ?? now())->copy()->startOfDay();

        if ($expiresAt === null) {
            return self::Expired;
        }

        // Expiry is a calendar date: the license is still valid on that day.
        $expiresAt = $expiresAt->copy()->startOfDay();

        if ($expiresAt->lt($today)) {
            return self::Expired;
        }

        if ($expiresAt->lte($today->copy()->addDays(self::EXPIRING_WINDOW_DAYS))) {
            return self::Expiring;
        }

        return self::Active;
    }
}
