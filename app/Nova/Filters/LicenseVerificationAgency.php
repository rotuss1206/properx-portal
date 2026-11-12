<?php

namespace App\Nova\Filters;

use App\Models\Agency;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The verification queue's "Agency": one agency, or individual members (no agency).
 */
class LicenseVerificationAgency extends Filter
{
    public const INDIVIDUAL = 'individual';

    public $name = 'Agency';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $value === self::INDIVIDUAL
            ? $query->whereNull('member_profiles.agency_id')
            : $query->where('member_profiles.agency_id', (int) $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['Individual members' => self::INDIVIDUAL]
            + Agency::query()->orderBy('legal_name')->pluck('id', 'legal_name')->map(fn ($id) => (string) $id)->all();
    }

    public function key(): string
    {
        return 'license-verification-agency';
    }
}
