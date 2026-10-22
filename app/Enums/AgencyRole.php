<?php

namespace App\Enums;

/**
 * A person's place in their agency. The principal is the agency admin (one per agency,
 * reassigned by staff only) and counts as person 1.
 */
enum AgencyRole: string
{
    case Principal = 'principal';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'Principal',
            self::Member => 'Member',
        };
    }
}
