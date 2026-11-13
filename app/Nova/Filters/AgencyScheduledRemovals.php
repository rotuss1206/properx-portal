<?php

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\BooleanFilter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Agencies with people leaving at the next renewal (G4).
 */
class AgencyScheduledRemovals extends BooleanFilter
{
    public $name = 'Removals';

    /**
     * @param  array<string, bool>  $value
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return ($value['scheduled'] ?? false)
            ? $query->whereHas('members', fn ($members) => $members->whereNotNull('removal_effective_at'))
            : $query;
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['People leaving at the renewal' => 'scheduled'];
    }

    public function key(): string
    {
        return 'agency-scheduled-removals';
    }
}
