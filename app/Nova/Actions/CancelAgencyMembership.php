<?php

namespace App\Nova\Actions;

use App\Enums\AgencyStatus;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Notifications\AgencyMembershipCanceled;
use App\Services\Stripe\AgencyPriceCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Stripe\Exception\ApiErrorException;

/**
 * "Cancel agency membership" (Executive Director only, AgencyPolicy::cancelMembership): ends the
 * agency's Stripe subscription at once or at the end of the paid period. At once, the agency and
 * its people become inactive now (Agency::changeStatus); at the period end they stay active until
 * Stripe ends the subscription, whose webhook makes them inactive. Nothing is deleted. The
 * principal is emailed; audited as agency_membership_canceled with the reason key and the timing.
 */
class CancelAgencyMembership extends Action
{
    public $name = 'Cancel agency membership';

    public $confirmText = 'Cancel this agency’s membership in Stripe? Nothing is deleted; the agency and its people become inactive (now, or when the paid period ends). There is no refund.';

    public $confirmButtonText = 'Cancel membership';

    public $cancelButtonText = 'Keep membership';

    /**
     * Why, from a fixed list (no free text: the audit log carries no personal data).
     */
    public const REASONS = [
        'agency_request' => 'At the agency’s request',
        'non_payment' => 'Non-payment',
        'agency_closed' => 'Agency closed or merged',
        'duplicate_enrollment' => 'Duplicate enrollment',
        'other' => 'Other',
    ];

    public const TIMINGS = [
        AgencyMembershipCanceled::PERIOD_END => 'At the end of the paid period',
        AgencyMembershipCanceled::NOW => 'Now',
    ];

    public function uriKey(): string
    {
        return 'cancel-agency-membership';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make('Reason')
                ->options(self::REASONS)
                ->rules('required', 'in:'.implode(',', array_keys(self::REASONS)))
                ->help('Recorded in the audit log.'),

            Select::make('When', 'timing')
                ->options(self::TIMINGS)
                ->rules('required', 'in:'.implode(',', array_keys(self::TIMINGS)))
                ->help('"Now" closes everyone’s access today; the period end keeps it until the paid date.'),
        ];
    }

    /**
     * @param  Collection<int, Agency>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $timing = (string) $fields->get('timing');
        $stripe = app(AgencyPriceCatalog::class)->client();
        $done = 0;

        foreach ($models as $agency) {
            if (blank($agency->stripe_subscription_id) || $agency->status === AgencyStatus::Inactive) {
                continue;
            }

            try {
                if ($timing === AgencyMembershipCanceled::NOW) {
                    $stripe->subscriptions->cancel($agency->stripe_subscription_id);
                } else {
                    $stripe->subscriptions->update($agency->stripe_subscription_id, ['cancel_at_period_end' => true]);
                }
            } catch (ApiErrorException $e) {
                Log::warning('Agency membership could not be canceled in Stripe', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);

                return Action::danger('Stripe did not cancel the subscription, so nothing was changed. Please try again in a few minutes.');
            }

            if ($timing === AgencyMembershipCanceled::NOW) {
                $agency->forceFill(['cancel_at_period_end' => false, 'payment_url' => null])->saveQuietly();
                $agency->changeStatus(AgencyStatus::Inactive);
            } else {
                $agency->forceFill(['cancel_at_period_end' => true])->saveQuietly();
            }

            AuditLog::record('agency_membership_canceled', $agency, details: ['reason' => (string) $fields->get('reason'), 'timing' => $timing]);
            $agency->principal?->notify(new AgencyMembershipCanceled($agency->getKey(), $timing));
            $done++;
        }

        if ($done === 0) {
            return Action::danger('Nothing to cancel: the agency has no Stripe subscription or is already inactive.');
        }

        return Action::message($timing === AgencyMembershipCanceled::NOW
            ? 'Membership canceled; the agency and its people are inactive.'
            : 'Membership set to end with the paid period; the agency stays active until then.');
    }
}
