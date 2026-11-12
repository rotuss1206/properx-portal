<?php

namespace App\Nova\Actions;

use App\Enums\LicenseVerification;
use App\Models\MemberProfile;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * The license verification queue (Agency spec 8.3): staff found the NPN and resident state in
 * NIPR or the state registry. Runs on one person or on a selection. MemberProfileObserver audits
 * each change as "license_verification_changed".
 */
class MarkLicenseVerified extends Action
{
    public $name = 'Verified';

    public $confirmText = 'Mark the selected licenses as verified? Check each NPN and resident state in NIPR or the state registry first.';

    public $confirmButtonText = 'Mark verified';

    public function uriKey(): string
    {
        return 'mark-license-verified';
    }

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $changed = 0;

        foreach ($models as $profile) {
            if ($profile->isAgencyEmployee() || $profile->license_verification === LicenseVerification::Verified) {
                continue;
            }

            $profile->update(['license_verification' => LicenseVerification::Verified]);
            $changed++;
        }

        return Action::message(trans_choice(':count license marked as verified.|:count licenses marked as verified.', $changed));
    }
}
