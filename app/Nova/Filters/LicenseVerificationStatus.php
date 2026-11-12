<?php

namespace App\Nova\Filters;

use App\Enums\LicenseVerification;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The verification queue's status: Pending review unless staff choose another.
 */
class LicenseVerificationStatus extends Filter
{
    public $name = 'Status';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $status = LicenseVerification::tryFrom((string) $value);

        return $status === null ? $query : $query->where('license_verification', $status->value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return collect(LicenseVerification::cases())
            ->mapWithKeys(fn (LicenseVerification $status) => [$status->label() => $status->value])
            ->all();
    }

    public function default(): string
    {
        return LicenseVerification::PendingReview->value;
    }

    public function key(): string
    {
        return 'license-verification-status';
    }
}
