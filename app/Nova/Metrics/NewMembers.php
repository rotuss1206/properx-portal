<?php

namespace App\Nova\Metrics;

use App\Enums\Role;
use App\Models\User;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Trend;
use Laravel\Nova\Metrics\TrendResult;

class NewMembers extends Trend
{
    public $name = 'New Members';

    public function calculate(NovaRequest $request): TrendResult
    {
        return $this->countByDays($request, User::role(Role::Member->value))->showSumValue();
    }

    public function ranges(): array
    {
        return [
            30 => '30 Days',
            60 => '60 Days',
            90 => '90 Days',
            365 => '365 Days',
        ];
    }

    public function uriKey(): string
    {
        return 'new-members';
    }
}
