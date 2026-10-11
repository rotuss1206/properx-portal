<?php

namespace App\Nova\Actions;

use App\Enums\VerificationStatus;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class RejectLicenseDocument extends ReviewLicenseDocument
{
    public $name = 'Reject';

    public function uriKey(): string
    {
        return 'reject-license-document';
    }

    protected function status(): VerificationStatus
    {
        return VerificationStatus::Rejected;
    }

    /**
     * The member needs to know why, so a reason is mandatory.
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make('Admin Notes')
                ->rules('required', 'max:2000')
                ->help('Shown to the member.'),
        ];
    }
}
