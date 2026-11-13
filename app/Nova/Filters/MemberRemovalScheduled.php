<?php

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\BooleanFilter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Agency people leaving at their agency's next renewal (G4).
 */
class MemberRemovalScheduled extends BooleanFilter
{
    public $name = 'Removal';

    /**
     * @param  array<string, bool>  $value
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return ($value['scheduled'] ?? false)
            ? $query->whereNotNull('agency_id')->whereNotNull('removal_effective_at')
            : $query;
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['Leaving the agency at the renewal' => 'scheduled'];
    }

    public function key(): string
    {
        return 'member-removal-scheduled';
    }
}
