<?php

namespace App\Nova\Actions;

use App\Enums\VerificationStatus;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class VerifyLicenseDocument extends ReviewLicenseDocument
{
    public $name = 'Verify';

    public function uriKey(): string
    {
        return 'verify-license-document';
    }

    protected function status(): VerificationStatus
    {
        return VerificationStatus::Approved;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make('Admin Notes')->rules('nullable', 'max:2000'),
        ];
    }
}
