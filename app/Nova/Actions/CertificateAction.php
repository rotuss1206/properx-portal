<?php

namespace App\Nova\Actions;

use App\Exceptions\CertificateUnavailableException;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * Hands the browser the server-side download route; the route re-checks access.
 */
abstract class CertificateAction extends Action
{
    public $withoutConfirmation = true;

    public $sole = true;

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $profile = $models->first();

        try {
            app(CertificateGeneratorService::class)->assertEligible($profile);
        } catch (CertificateUnavailableException $e) {
            return Action::danger($e->getMessage());
        }

        return Action::download(
            CertificateGeneratorService::downloadUrl($profile),
            "ProPerx-membership-certificate-{$profile->certificate_code}.pdf",
        );
    }
}
