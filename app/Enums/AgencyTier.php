<?php

namespace App\Enums;

/**
 * Agency pricing tier, chosen by headcount (the principal counts as one).
 */
enum AgencyTier: string
{
    case Agency = 'agency_2to9';
    case AgencyPlus = 'agency_10to49';
    case AgencyEnterprise = 'agency_50plus';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::AgencyPlus => 'Agency Plus',
            self::AgencyEnterprise => 'Agency Enterprise',
        };
    }
}
