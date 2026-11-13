<?php

namespace App\Support;

use App\Enums\AgencyStatus;
use App\Models\MemberProfile;
use App\Models\User;

/**
 * The payment banner for a member (Spec 7; agency people: Agency spec 6), shown by Nova
 * (resources/js/nova/membership-banner.js) and the member area (x-member.membership-banner),
 * or null when none is due. Agency people follow their agency: past due keeps access and only the
 * principal is asked to pay; inactive closes access for everyone.
 */
final class MembershipBanner
{
    /**
     * @return array{level: string, message: string, paymentUrl: ?string}|null
     */
    public static function for(?User $user): ?array
    {
        $profile = $user?->isMember() ? $user->memberProfile : null;

        if ($profile === null) {
            return null;
        }

        if ($profile->agency_id !== null) {
            return self::forAgencyPerson($profile);
        }

        $paymentUrl = config('portal.payments.payment_url') ?: null;

        if ($profile->hasRestrictedMembership()) {
            return [
                'level' => 'danger',
                'message' => 'Your membership is inactive. Your certificate, the library and license documents are unavailable until your dues are paid.',
                'paymentUrl' => $paymentUrl,
            ];
        }

        if ($profile->isInPaymentGracePeriod()) {
            return [
                'level' => 'warning',
                'message' => 'Your membership payment is past due. Please pay by '
                    .$profile->paymentGraceEndsAt()->toFormattedDateString().' to keep full access.',
                'paymentUrl' => $paymentUrl,
            ];
        }

        return null;
    }

    /**
     * @return array{level: string, message: string, paymentUrl: ?string}|null
     */
    private static function forAgencyPerson(MemberProfile $profile): ?array
    {
        $agency = $profile->agency;
        // The agency's own record says who the principal is (agencies.principal_user_id).
        $principal = $agency !== null && $agency->principal_user_id === $profile->user_id;
        // Stripe's page of the overdue invoice, else the payment portal; for the principal only.
        $paymentUrl = $principal ? ($agency?->payment_url ?: (config('portal.payments.payment_url') ?: null)) : null;

        if ($profile->hasRestrictedMembership()) {
            return [
                'level' => 'danger',
                'message' => $agency?->status === AgencyStatus::Inactive
                    ? "Your agency's membership has ended, so your membership is inactive. Your certificate, the library and license documents are unavailable."
                        .($principal ? ' Renew the membership to restore everyone\'s access.' : ' Your agency\'s principal can renew it.')
                    : 'Your membership is inactive. Please contact your agency\'s principal or ProPerX.',
                'paymentUrl' => $paymentUrl,
            ];
        }

        if ($profile->isInPaymentGracePeriod()) {
            $until = $agency?->graceEndsAt()?->toFormattedDateString();

            return [
                'level' => 'warning',
                'message' => "Your agency's membership payment is overdue."
                    .($principal
                        ? ' Please pay by '.($until ?? 'the due date').' to keep everyone\'s access.'
                        : ' Your agency\'s principal has been asked to pay; your access continues'.($until ? " until {$until}." : ' for now.')),
                'paymentUrl' => $paymentUrl,
            ];
        }

        return null;
    }
}
