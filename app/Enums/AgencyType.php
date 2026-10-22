<?php

namespace App\Enums;

/**
 * Line of business of an agency (agency enrollment, Screen 3).
 */
enum AgencyType: string
{
    case PropertyCasualty = 'p_and_c';
    case HealthLife = 'health_and_life';
    case MultiLine = 'multi_line';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PropertyCasualty => 'P&C',
            self::HealthLife => 'Health and Life',
            self::MultiLine => 'Multi-line',
            self::Other => 'Other',
        };
    }
}
