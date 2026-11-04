<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case LicenseExpiry = 'license_expiry';
    case ComplianceBriefings = 'compliance_briefings';
    case TaxDocuments = 'tax_documents';
    case Announcements = 'announcements';

    public function label(): string
    {
        return match ($this) {
            self::LicenseExpiry => 'License expiry',
            self::ComplianceBriefings => 'Compliance briefings',
            self::TaxDocuments => 'Tax documents available',
            self::Announcements => 'General announcements',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LicenseExpiry => 'Reminders 60, 30 and 7 days before your license expires.',
            self::ComplianceBriefings => 'New compliance and training material in the library.',
            self::TaxDocuments => 'When ProPerX adds a tax document to your account.',
            self::Announcements => 'News from ProPerX Alliance for its members.',
        };
    }
}
