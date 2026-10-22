<?php

namespace App\Enums;

/**
 * Founding rates are locked for as long as the agency stays a member without a break;
 * a lapse rejoins at standard rates.
 */
enum RateType: string
{
    case Founding = 'founding';
    case Standard = 'standard';

    public function label(): string
    {
        return match ($this) {
            self::Founding => 'Founding',
            self::Standard => 'Standard',
        };
    }
}
