<?php

namespace App\Services\Stripe;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Applies Stripe membership payment events to member profiles (Spec §7).
 *
 * Runs as the API Service account. Each event id is processed once; events older
 * than the profile's current status are ignored, as Stripe does not guarantee order.
 */
class StripeWebhookHandler
{
    public const HANDLED_EVENTS = [
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    /**
     * @return string Outcome: duplicate, ignored, unmatched, stale, unchanged or updated.
     */
    public function handle(Event $event, User $serviceAccount): string
    {
        if (! in_array($event->type, self::HANDLED_EVENTS, true)) {
            return 'ignored';
        }

        try {
            return DB::transaction(function () use ($event, $serviceAccount) {
                // Claim the event id first: a redelivery hits the primary key and stops here.
                $record = StripeEvent::create(['id' => $event->id, 'type' => $event->type, 'outcome' => 'processing']);

                [$outcome, $profile] = $this->apply($event, $serviceAccount);

                $record->update(['outcome' => $outcome, 'member_profile_id' => $profile?->getKey()]);
                AuditLog::record('stripe_event_processed', $profile, $serviceAccount);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    /**
     * @return array{0: string, 1: MemberProfile|null}
     */
    private function apply(Event $event, User $serviceAccount): array
    {
        $object = $event->data->object;
        $customerId = is_string($object->customer ?? null) ? $object->customer : null;
        $email = $object->customer_email ?? null;

        $profile = $this->findProfile($customerId, $email);

        if ($profile === null) {
            Log::warning('Stripe event could not be matched to a member', [
                'event' => $event->id,
                'type' => $event->type,
                'customer' => $customerId,
                'email' => $email,
            ]);

            return ['unmatched', null];
        }

        if ($customerId !== null && $profile->stripe_customer_id === null) {
            $profile->stripe_customer_id = $customerId;
        }

        $status = $this->statusFor($event);
        $occurredAt = Carbon::createFromTimestamp($event->created);

        if ($profile->payment_event_at !== null && $occurredAt->lt($profile->payment_event_at)) {
            $profile->save(); // keep a newly linked customer id

            return ['stale', $profile];
        }

        $profile->payment_event_at = $occurredAt;

        if ($status === null || $status === $profile->payment_status) {
            $profile->save();

            return ['unchanged', $profile];
        }

        $profile->payment_status = $status;
        $profile->payment_status_updated_at = $occurredAt; // grace period counts from the change
        $profile->save();

        AuditLog::record('payment_status_changed', $profile, $serviceAccount);

        return ['updated', $profile];
    }

    private function statusFor(Event $event): ?PaymentStatus
    {
        $object = $event->data->object;

        return match ($event->type) {
            'customer.subscription.updated' => PaymentStatus::fromSubscriptionStatus((string) ($object->status ?? '')),
            'customer.subscription.deleted' => PaymentStatus::Canceled,
            'invoice.paid' => PaymentStatus::Active,
            'invoice.payment_failed' => PaymentStatus::PastDue,
            default => null,
        };
    }

    /**
     * Match by Stripe customer id first, then by the member's login email.
     */
    private function findProfile(?string $customerId, ?string $email): ?MemberProfile
    {
        if ($customerId !== null) {
            $profile = MemberProfile::where('stripe_customer_id', $customerId)->first();

            if ($profile !== null) {
                return $profile;
            }
        }

        if (blank($email)) {
            return null;
        }

        return MemberProfile::query()
            ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [strtolower($email)]))
            ->first();
    }
}
