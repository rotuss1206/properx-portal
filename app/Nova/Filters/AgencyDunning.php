<?php

namespace App\Nova\Filters;

use App\Enums\AgencyStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Agencies by dunning state (H2): past due within the grace period, or expired (inactive).
 */
class AgencyDunning extends Filter
{
    public $name = 'Dunning';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return match ($value) {
            'grace' => $query->where('status', AgencyStatus::PastDue->value),
            'expired' => $query->where('status', AgencyStatus::Inactive->value),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['Past due, in the grace period' => 'grace', 'Expired (inactive)' => 'expired'];
    }

    public function key(): string
    {
        return 'agency-dunning';
    }
}
