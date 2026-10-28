<?php

namespace App\Nova\Actions;

use App\Enums\RateType;

class ChangeAgencyRateType extends ChangeAgencyPricing
{
    public $name = 'Change rate type';

    public $confirmText = 'Founding rates are locked at payment and kept while the agency remains a member without a break. Change it only for an exception.';

    public function uriKey(): string
    {
        return 'change-agency-rate-type';
    }

    protected function attribute(): string
    {
        return 'rate_type';
    }

    protected function auditAction(): string
    {
        return 'agency_rate_type_changed';
    }

    protected function cases(): array
    {
        return RateType::cases();
    }
}
