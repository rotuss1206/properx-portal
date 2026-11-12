<?php

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The verification queue's "Type": agency people, agency principals or individual members.
 */
class LicenseVerificationType extends Filter
{
    public const AGENCY_PERSON = 'agency_person';

    public const PRINCIPAL = 'principal';

    public const INDIVIDUAL = 'individual';

    public $name = 'Type';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $principals = fn ($sub) => $sub->select('principal_user_id')->from('agencies')->whereNotNull('principal_user_id');

        return match ($value) {
            self::PRINCIPAL => $query->whereNotNull('member_profiles.agency_id')->whereIn('member_profiles.user_id', $principals),
            self::AGENCY_PERSON => $query->whereNotNull('member_profiles.agency_id')->whereNotIn('member_profiles.user_id', $principals),
            self::INDIVIDUAL => $query->whereNull('member_profiles.agency_id'),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['Agency person' => self::AGENCY_PERSON, 'Agency principal' => self::PRINCIPAL, 'Individual member' => self::INDIVIDUAL];
    }

    public function key(): string
    {
        return 'license-verification-type';
    }
}
