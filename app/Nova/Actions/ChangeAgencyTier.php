<?php

namespace App\Nova\Actions;

use App\Enums\AgencyTier;

class ChangeAgencyTier extends ChangeAgencyPricing
{
    public $name = 'Change tier';

    public $confirmText = 'The tier normally follows the headcount. Change it only for an exception agreed with the agency.';

    public function uriKey(): string
    {
        return 'change-agency-tier';
    }

    protected function attribute(): string
    {
        return 'tier';
    }

    protected function auditAction(): string
    {
        return 'agency_tier_changed';
    }

    protected function cases(): array
    {
        return AgencyTier::cases();
    }
}
