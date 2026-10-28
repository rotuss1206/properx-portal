<?php

namespace App\Nova\Metrics;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use Illuminate\Support\Str;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

class LicenseStatusPartition extends Partition
{
    public $name = 'License Status';

    public function calculate(NovaRequest $request): PartitionResult
    {
        // Agency employees have no license status.
        return $this->count($request, MemberProfile::query()->licensed(), 'license_status')
            ->label(fn ($value) => Str::headline($value instanceof LicenseStatus ? $value->value : (string) $value))
            ->colors([
                LicenseStatus::Active->value => '#16a34a',
                LicenseStatus::Expiring->value => '#f59e0b',
                LicenseStatus::Expired->value => '#dc2626',
            ]);
    }

    public function uriKey(): string
    {
        return 'license-status-partition';
    }
}
