<?php

namespace App\Enums;

/**
 * A license document's review status, in the same words on every member page. Not to be confused
 * with App\Enums\LicenseVerification, the license's own status.
 */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Its icon in public/portal/img/icons.svg.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Approved => 'check',
            self::Rejected => 'close',
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
