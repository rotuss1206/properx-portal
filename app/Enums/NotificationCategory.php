<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case LicenseExpiry = 'license_expiry';
    case ComplianceBriefings = 'compliance_briefings';
    case TaxDocuments = 'tax_documents';
    case Announcements = 'announcements';
}
