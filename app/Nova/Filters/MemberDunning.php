<?php

namespace App\Nova\Filters;

use App\Enums\AgencyStatus;
use App\Enums\PaymentStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * People by where a failed payment has got to (H2), as MemberProfile::dunning() puts it: agency
 * people by their agency's status, individual members by their own payment status and grace period.
 */
class MemberDunning extends Filter
{
    public $name = 'Dunning';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $graceStart = now()->subDays((int) config('portal.payments.grace_days'));

        return match ($value) {
            'grace' => $query->where(fn ($q) => $q
                ->where(fn ($agency) => $agency->whereNotNull('agency_id')->whereHas('agency', fn ($a) => $a->where('status', AgencyStatus::PastDue->value)))
                ->orWhere(fn ($own) => $own->whereNull('agency_id')->where('payment_status', PaymentStatus::PastDue->value)->where('payment_status_updated_at', '>', $graceStart))),
            'expired' => $query->where(fn ($q) => $q
                ->where(fn ($agency) => $agency->whereNotNull('agency_id')->whereHas('agency', fn ($a) => $a->where('status', AgencyStatus::Inactive->value)))
                ->orWhere(fn ($own) => $own->whereNull('agency_id')->where(fn ($s) => $s
                    ->where('payment_status', PaymentStatus::Canceled->value)
                    ->orWhere(fn ($late) => $late->where('payment_status', PaymentStatus::PastDue->value)->where('payment_status_updated_at', '<=', $graceStart))))),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['In the grace period' => 'grace', 'Expired' => 'expired'];
    }

    public function key(): string
    {
        return 'member-dunning';
    }
}
