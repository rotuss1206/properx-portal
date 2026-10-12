<?php

namespace App\Support;

use App\Models\User;

/**
 * Text of the Nova payment banner for a member (Spec 7), or null when none is due.
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
}
