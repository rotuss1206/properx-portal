<?php

namespace App\Enums;

/**
 * A license document's review status. The words come from the mockups and are the same on every
 * member page. Not to be confused with App\Enums\LicenseVerification, the license's own status.
 */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The badge's look (x-ppx.badge).
     */
    public function variant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
