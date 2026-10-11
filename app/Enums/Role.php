<?php

namespace App\Enums;

/**
 * Spatie role names (Spec §3.7).
 */
enum Role: string
{
    case ExecutiveDirector = 'Executive Director';
    case MembershipCoordinator = 'Membership Coordinator';
    case ComplianceSpecialist = 'Compliance Specialist';
    case Member = 'Member';
    case ApiService = 'API Service';

    /**
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::ExecutiveDirector, self::MembershipCoordinator, self::ComplianceSpecialist];
    }
}
