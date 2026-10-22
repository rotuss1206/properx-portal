<?php

namespace App\Enums;

/**
 * Agency employees have no NPN and can join only through an agency enrollment.
 */
enum PersonType: string
{
    case LicensedProducer = 'licensed_producer';
    case AgencyEmployee = 'agency_employee';

    public function label(): string
    {
        return match ($this) {
            self::LicensedProducer => 'Licensed producer',
            self::AgencyEmployee => 'Agency employee',
        };
    }
}
