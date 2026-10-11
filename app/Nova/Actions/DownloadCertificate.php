<?php

namespace App\Nova\Actions;

use App\Models\MemberProfile;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * Opens the membership certificate PDF; access is re-checked by CertificateController.
 */
class DownloadCertificate extends Action
{
    public $name = 'Download Certificate';

    public $withoutConfirmation = true;

    public $sole = true;

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $profile = $models->first();

        return Action::openInNewTab(route('certificates.show', $profile));
    }
}
