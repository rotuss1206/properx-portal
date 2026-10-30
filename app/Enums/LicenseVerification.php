<?php

namespace App\Enums;

/**
 * Staff's verification of a person's license (NPN and resident state). Agency employees hold
 * no license and have none (null).
 */
enum LicenseVerification: string
{
    case Verified = 'verified';
    case PendingReview = 'pending_review';
    case NeedsAttention = 'needs_attention';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::PendingReview => 'Pending review',
            self::NeedsAttention => 'Needs attention',
        };
    }
}
