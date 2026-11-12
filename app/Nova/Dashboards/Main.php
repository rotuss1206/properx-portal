<?php

namespace App\Nova\Dashboards;

use App\Nova\Cards\PendingVerification;
use App\Nova\LicenseVerification;
use App\Nova\Metrics\ExpiringLicenses;
use App\Nova\Metrics\LicenseStatusPartition;
use App\Nova\Metrics\NewMembers;
use App\Nova\Metrics\UpcomingExpirations;
use Illuminate\Http\Request;
use Laravel\Nova\Card;
use Laravel\Nova\Dashboards\Main as Dashboard;

/**
 * Staff dashboard. Visibility is set in NovaServiceProvider::dashboards().
 */
class Main extends Dashboard
{
    public function name()
    {
        return 'Dashboard';
    }

    /**
     * Get the cards for the dashboard.
     *
     * @return array<int, Card>
     */
    public function cards(): array
    {
        $staffOnly = fn (Request $request) => (bool) $request->user()?->isStaff();

        return [
            // The license verification queue (Agency spec 8.3), for those who work it.
            PendingVerification::make()->canSee(fn (Request $request) => LicenseVerification::canVerify($request)),
            ExpiringLicenses::make()->canSee($staffOnly),
            LicenseStatusPartition::make()->canSee($staffOnly),
            NewMembers::make()->canSee($staffOnly),
            UpcomingExpirations::make()->canSee($staffOnly)->width('full'),
        ];
    }
}
