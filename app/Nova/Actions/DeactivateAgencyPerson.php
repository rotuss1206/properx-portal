<?php

namespace App\Nova\Actions;

use App\Enums\MemberStatus;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class DeactivateAgencyPerson extends SetAgencyPersonStatus
{
    public $name = 'Deactivate person';

    public $confirmText = 'Set this person inactive? They keep their account but lose the certificate, library and documents. The agency’s invoice does not change before renewal.';

    public $confirmButtonText = 'Deactivate';

    /**
     * Why, from a fixed list (no free text: the audit log carries no personal data).
     */
    public const REASONS = [
        'left_agency' => 'Left the agency',
        'principal_request' => "At the principal's request",
        'no_longer_eligible' => 'No longer eligible',
        'duplicate_account' => 'Duplicate account',
        'other' => 'Other',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make('Reason')
                ->options(self::REASONS)
                ->rules('required', 'in:'.implode(',', array_keys(self::REASONS)))
                ->help('Recorded in the audit log.'),
        ];
    }

    public function uriKey(): string
    {
        return 'deactivate-agency-person';
    }

    protected function status(): MemberStatus
    {
        return MemberStatus::Inactive;
    }
}
