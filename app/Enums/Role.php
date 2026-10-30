<?php

namespace App\Enums;

/**
 * Spatie role names (Spec 3.7).
 */
enum Role: string
{
    case ExecutiveDirector = 'Executive Director';
    case MembershipCoordinator = 'Membership Coordinator';
    case ComplianceSpecialist = 'Compliance Specialist';
    case Member = 'Member';
    /** Layered on Member: the admin of one agency, limited to that agency. */
    case AgencyPrincipal = 'Agency Principal';
    case ApiService = 'API Service';

    /**
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::ExecutiveDirector, self::MembershipCoordinator, self::ComplianceSpecialist];
    }

    /**
     * Staff roles that may be handed out in Nova. The Executive Director role is assigned
     * and revoked only with `php artisan app:assign-executive-director`.
     *
     * @return list<self>
     */
    public static function assignableStaff(): array
    {
        return [self::MembershipCoordinator, self::ComplianceSpecialist];
    }
}
