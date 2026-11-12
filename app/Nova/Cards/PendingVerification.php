<?php

namespace App\Nova\Cards;

use App\Enums\LicenseVerification as Verification;
use App\Models\MemberProfile;
use App\Nova\LicenseVerification;
use Laravel\Nova\Card;
use Laravel\Nova\Nova;

/**
 * Staff dashboard: how many licenses wait in the verification queue, with a link to it (Agency
 * spec 8.3). Drawn by resources/js/nova/cards.js ("ppx-link-metric"), render functions only.
 */
class PendingVerification extends Card
{
    public $component = 'ppx-link-metric';

    public static function count(): int
    {
        return MemberProfile::query()
            ->licensed()
            ->where('license_verification', Verification::PendingReview->value)
            ->count();
    }

    /**
     * The queue opens on Pending review (LicenseVerificationStatus's default).
     */
    public static function queueUrl(): string
    {
        return Nova::url('/resources/'.LicenseVerification::uriKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $count = self::count();

        return array_merge(parent::jsonSerialize(), [
            'title' => 'Pending verification',
            'value' => $count,
            'suffix' => $count === 1 ? 'license' : 'licenses',
            'url' => self::queueUrl(),
            'linkText' => 'Open the verification queue',
        ]);
    }
}
