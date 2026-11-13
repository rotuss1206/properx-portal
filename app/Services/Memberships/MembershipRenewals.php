<?php

namespace App\Services\Memberships;

use App\Enums\AgencyStatus;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReminderType;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Member\AgencyMembershipEnded;
use App\Notifications\Member\MembershipExpired;
use App\Notifications\Member\PaymentOverdueReminder;
use App\Notifications\Member\RenewalReminder;
use App\Services\Stripe\BillingReader;
use App\Services\Stripe\BillingUnavailableException;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Renewals of agencies and individual members (H1-H3):
 *  - reminders before the paid period ends (portal.renewals.reminder_days), by email and in the bell;
 *  - reminders while a renewal payment is overdue (portal.renewals.grace_reminder_days before access ends);
 *  - the end: past due beyond portal.payments.grace_days (0: at once), or the subscription ended.
 *    An agency becomes inactive with its people; the principal or the individual member gets
 *    "Your membership has expired", the agency's people that the agency's membership ended.
 * Every message is written to reminder_logs first (unique per person, type and paid period), so
 * nothing goes twice, whoever calls (the scheduled commands, Stripe's webhooks, a staff action).
 * A later payment restores everything through the payment's webhook.
 */
class MembershipRenewals
{
    public function __construct(private BillingReader $stripe) {}

    // Before the period ends

    /**
     * The reminder due now for a period ending at $paidThrough: the smallest configured day count
     * the days left fit into (a missed earlier one is not sent once a later one is due).
     */
    public static function dueRenewalReminder(CarbonInterface $paidThrough): ?ReminderType
    {
        $left = (int) today(config('portal.schedule_timezone'))->diffInDays($paidThrough->copy()->timezone(config('portal.schedule_timezone'))->startOfDay(), false);

        if ($left < 1) {
            return null;
        }

        $days = collect((array) config('portal.renewals.reminder_days'))->filter(fn ($d) => $d >= $left)->sort()->first();

        return $days === null ? null : ReminderType::renewal((int) $days);
    }

    public function remindAgency(Agency $agency): bool
    {
        $principal = $agency->principal;
        $type = $agency->paid_through_at ? self::dueRenewalReminder($agency->paid_through_at) : null;

        if ($principal === null || $type === null || $agency->status !== AgencyStatus::Active || $agency->cancel_at_period_end) {
            return false;
        }

        return $this->once($principal, $type, $agency->paid_through_at, function () use ($agency, $principal, $type) {
            $facts = $this->stripeFacts($agency->stripe_customer_id, $agency->stripe_subscription_id);

            // Ending with the period (canceled in Stripe): no renewal to remind of.
            if ($facts['cancels'] ?? false) {
                return false;
            }

            $principal->notifyNow(new RenewalReminder(RenewalReminder::AGENCY, $type->days(), $agency->paid_through_at->toDateString(), [
                'people' => $agency->billingHeadcount(),
                'tier' => $agency->tier?->label(),
                'amount' => $facts['amount'] ?? ($agency->currentRate() === null ? null : $agency->currentRate() * $agency->billingHeadcount()),
                'method' => $facts['method'] ?? null,
            ]));

            return true;
        });
    }

    public function remindIndividual(MemberProfile $profile): bool
    {
        $type = $profile->paid_through_at ? self::dueRenewalReminder($profile->paid_through_at) : null;

        if ($type === null || $profile->agency_id !== null || blank($profile->stripe_customer_id) || $profile->payment_status !== PaymentStatus::Active || $profile->user === null) {
            return false;
        }

        return $this->once($profile->user, $type, $profile->paid_through_at, function () use ($profile, $type) {
            $facts = $this->stripeFacts($profile->stripe_customer_id, $profile->stripe_subscription_id);

            if (($facts['cancels'] ?? false) || ($facts['read'] && ! $facts['subscription'])) {
                return false;
            }

            $profile->user->notifyNow(new RenewalReminder(RenewalReminder::INDIVIDUAL, $type->days(), $profile->paid_through_at->toDateString(), [
                'amount' => $facts['amount'] ?? null,
                'method' => $facts['method'] ?? null,
            ]));

            return true;
        });
    }

    // While overdue, and the end

    /**
     * The overdue reminder due for an access end at $accessEnds (days left of the grace period).
     */
    public static function dueGraceReminder(CarbonInterface $accessEnds): ?ReminderType
    {
        $left = (int) ceil(now()->diffInHours($accessEnds, false) / 24);

        if ($left < 1) {
            return null;
        }

        $days = collect((array) config('portal.renewals.grace_reminder_days'))->filter(fn ($d) => $d >= $left)->sort()->first();

        return $days === null ? null : ReminderType::grace((int) $days);
    }

    /**
     * A past-due agency: a reminder while the grace period runs, the end once it is over.
     */
    public function checkPastDueAgency(Agency $agency): string
    {
        $ends = $agency->graceEndsAt();

        if ($ends === null) {
            return 'none';
        }

        if (! $ends->isFuture()) {
            $this->expireAgency($agency);

            return 'expired';
        }

        $type = self::dueGraceReminder($ends);

        if ($type === null || $agency->principal === null) {
            return 'none';
        }

        return $this->once($agency->principal, $type, $agency->paid_through_at ?? $agency->past_due_since, function () use ($agency, $ends) {
            $agency->principal->notify(new PaymentOverdueReminder(RenewalReminder::AGENCY, $ends->toDateString()));

            return true;
        }) ? 'reminded' : 'none';
    }

    /**
     * A past-due individual member (their access follows payment_status; see
     * MemberProfile::hasRestrictedMembership): a reminder while the grace period runs, the
     * "expired" email once it is over.
     */
    public function checkPastDueIndividual(MemberProfile $profile): string
    {
        $ends = $profile->paymentGraceEndsAt();

        if ($ends === null || $profile->user === null) {
            return 'none';
        }

        if (! $ends->isFuture()) {
            return $this->expireIndividual($profile) ? 'expired' : 'none';
        }

        $type = self::dueGraceReminder($ends);

        if ($type === null) {
            return 'none';
        }

        return $this->once($profile->user, $type, $profile->paid_through_at ?? $profile->payment_status_updated_at, function () use ($profile, $ends) {
            $profile->user->notify(new PaymentOverdueReminder(RenewalReminder::INDIVIDUAL, $ends->toDateString()));

            return true;
        }) ? 'reminded' : 'none';
    }

    /**
     * The agency's membership is over: inactive with its people (Agency::changeStatus), the
     * principal told it expired (unless $principalTold: a staff cancellation already wrote to
     * them), each other person that the agency's membership ended. Once per paid period.
     */
    public function expireAgency(Agency $agency, bool $principalTold = false): void
    {
        $agency->changeStatus(AgencyStatus::Inactive);
        $period = $agency->paid_through_at ?? $agency->past_due_since ?? now();

        if ($agency->principal !== null) {
            $this->once($agency->principal, ReminderType::MembershipExpired, $period, function () use ($agency, $principalTold) {
                if ($principalTold) {
                    return false; // logged as skipped: the cancellation email said it
                }

                $agency->principal->notify(new MembershipExpired(RenewalReminder::AGENCY));

                return true;
            });
        }

        $agency->members()->with('user')->where('user_id', '!=', (int) $agency->principal_user_id)->each(function (MemberProfile $person) use ($period) {
            if ($person->user !== null) {
                $this->once($person->user, ReminderType::AgencyMembershipEnded, $period, function () use ($person) {
                    $person->user->notify(new AgencyMembershipEnded);

                    return true;
                });
            }
        });
    }

    /**
     * An individual member's membership is over (canceled, or past due beyond the grace period):
     * "Your membership has expired", once per paid period. False when it was sent already.
     */
    public function expireIndividual(MemberProfile $profile): bool
    {
        if ($profile->user === null || $profile->agency_id !== null) {
            return false;
        }

        return $this->once($profile->user, ReminderType::MembershipExpired, $profile->paid_through_at ?? $profile->payment_status_updated_at ?? now(), function () use ($profile) {
            $profile->user->notify(new MembershipExpired(RenewalReminder::INDIVIDUAL));

            return true;
        });
    }

    // Helpers

    /**
     * Writes the reminder log first (unique per person, type and period), then sends; a second
     * caller for the same reminder stops at the unique index. $send returns false to log it as
     * skipped instead.
     *
     * @param  callable(): bool  $send
     */
    private function once(User $user, ReminderType $type, ?CarbonInterface $period, callable $send): bool
    {
        $periodEnds = ($period ?? now())->toDateString();

        try {
            $log = DB::transaction(fn () => $user->reminderLogs()->create([
                'reminder_type' => $type,
                'period_ends_at' => $periodEnds,
                'sent_at' => now(),
                'delivery_status' => DeliveryStatus::Sent,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if (! $send()) {
            $log->update(['delivery_status' => DeliveryStatus::Skipped]);

            return false;
        }

        return true;
    }

    /**
     * What Stripe says about the coming renewal: the amount, the payment method and whether the
     * subscription ends with the period. Empty when Stripe is not set up or does not answer: the
     * reminder then goes with the portal's own figures.
     *
     * @return array{read: bool, subscription?: bool, amount?: ?int, method?: ?string, cancels?: bool}
     */
    private function stripeFacts(?string $customerId, ?string $subscriptionId): array
    {
        if (blank($customerId) || ! BillingReader::isConfigured()) {
            return ['read' => false];
        }

        try {
            $billing = $this->stripe->read((string) $customerId, $subscriptionId);
        } catch (BillingUnavailableException) {
            return ['read' => false];
        }

        $subscription = $billing['subscription'];

        return [
            'read' => true,
            'subscription' => $subscription !== null,
            'amount' => $subscription['amount'] ?? null,
            'method' => $billing['payment_method']['label'] ?? null,
            'cancels' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ];
    }
}
