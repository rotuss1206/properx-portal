<?php

namespace App\Support;

use App\Models\User;

/**
 * The "Renewal due" card in the Nova sidebar: shown to members whose paid period
 * (paid_through_at, from Stripe) ends within the next 90 days. Never shown to staff.
 */
final class RenewalNotice
{
    public const WINDOW_DAYS = 90;

    /**
     * @return array{days: int, paymentUrl: ?string}|null
     */
    public static function for(?User $user): ?array
    {
        if ($user === null || ! $user->isMember() || $user->isStaff()) {
            return null;
        }

        $profile = $user->memberProfile;

        if ($profile === null) {
            return null;
        }

        // Agency people renew on the agency's invoice, which the principal pays.
        if ($profile->agency_id !== null) {
            $paidThrough = $profile->isAgencyPrincipal() ? $profile->agency?->paid_through_at : null;
        } else {
            $paidThrough = $profile->paid_through_at;
        }

        if ($paidThrough === null) {
            return null;
        }

        // An ended period is the payment banner's business (past due / inactive).
        $days = (int) today()->diffInDays($paidThrough->copy()->startOfDay(), false);

        if ($days < 0 || $days > self::WINDOW_DAYS) {
            return null;
        }

        return [
            'days' => $days,
            'paymentUrl' => config('portal.payments.payment_url') ?: null,
        ];
    }
}
