<?php

namespace App\Nova\Metrics;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class ExpiringLicenses extends Value
{
    public $name = 'Expiring in 60 Days';

    public $icon = 'clock';

    public function calculate(NovaRequest $request): ValueResult
    {
        // By date rather than stored status, so it is accurate even before the daily job runs.
        $count = MemberProfile::query()
            // Compare plain dates: a datetime bound would drop licenses expiring today on some drivers.
            ->whereBetween('license_expires_at', [today()->toDateString(), today()->addDays(LicenseStatus::EXPIRING_WINDOW_DAYS)->toDateString()])
            ->count();

        return $this->result($count)->suffix('licenses')->allowZeroResult();
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'expiring-licenses';
    }
}
