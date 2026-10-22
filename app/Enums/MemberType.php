<?php

namespace App\Enums;

enum MemberType: string
{
    case Individual = 'individual';
    case AgencyMember = 'agency_member';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::AgencyMember => 'Agency member',
        };
    }
}
