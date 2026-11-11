<?php

namespace App\Services\Stripe;

use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Notifications\AgencyBankPaymentFailed;
use App\Notifications\AgencyEnrollmentCompleted;
use App\Services\Agencies\AgencyCheckout;
use App\Services\Agencies\AgencyEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Event;
use Stripe\StripeObject;
use Throwable;

/**
 * Stripe events of agencies, picked out before any individual member is looked for, so an
 * agency's event never changes the principal's own profile (and the email fallback for
 * individuals never applies to an agency's customer). An agency is recognized by
 * agencies.stripe_customer_id or, before it exists, by the enrollment's metadata on the
 * subscription (properx_kind = agency_enrollment). Handled here so far: the first payment
 * (invoice.paid enrolls the agency if the browser has not yet), a failed bank payment of an
 * enrollment (it is open again), and the paid period. Renewals, changes and cancellations of
 * enrolled agencies come next; they are acknowledged and left alone.
 */
class AgencyStripeEvents
{
    public function __construct(private AgencyEnrollment $enrollment) {}

    /**
     * @return string|null the outcome, or null when the event is not an agency's
     */
    public function handle(Event $event): ?string
    {
        $object = $event->data->object;
        $customerId = is_string($object->customer ?? null) ? $object->customer : null;
        $metadata = self::subscriptionMetadata($event);
        $agency = $customerId === null ? null : Agency::query()->where('stripe_customer_id', $customerId)->first();

        if ($agency === null && ($metadata['properx_kind'] ?? null) !== AgencyCheckout::METADATA_KIND) {
            return null;
        }

        return match ($event->type) {
            'invoice.paid' => $this->paid($event, $agency, $metadata, $customerId),
            'invoice.payment_failed' => $agency === null ? $this->failed($metadata) : 'agency_ignored',
            default => 'agency_ignored',
        };
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function paid(Event $event, ?Agency $agency, array $metadata, ?string $customerId): string
    {
        $object = $event->data->object;
        $paidThrough = self::paidThrough($object);

        if ($agency !== null) {
            if ($paidThrough !== null && ($agency->paid_through_at === null || $paidThrough->gt($agency->paid_through_at))) {
                $agency->forceFill(['paid_through_at' => $paidThrough])->saveQuietly();
            }

            return 'agency_paid';
        }

        $subscriptionId = self::subscriptionId($object);
        $draft = AgencyRegistrationDraft::query()->find((int) ($metadata['properx_draft_id'] ?? 0));

        if ($subscriptionId === null || $customerId === null || ! is_array($draft?->data['payment']['snapshot'] ?? null)) {
            Log::warning('Agency enrollment paid but its draft is gone', ['event' => $event->id, 'subscription' => $subscriptionId]);

            return 'agency_unmatched';
        }

        $wasProcessing = ($draft->data['payment']['status'] ?? null) === 'processing';
        $principalEmail = (string) ($draft->data['payment']['snapshot']['principal']['email'] ?? '');
        $result = $this->enrollment->complete($draft, $subscriptionId, $customerId, $paidThrough);

        // A bank payment: the person left the page while it was processing.
        if ($result['created'] && $wasProcessing && $principalEmail !== '') {
            Notification::route('mail', $principalEmail)->notify(new AgencyEnrollmentCompleted);
        }

        return $result['created'] ? 'agency_enrolled' : 'agency_paid';
    }

    /**
     * A bank payment that was processing failed: the enrollment opens again, and the principal
     * gets a link back to it. A card declined while the person is on the page also fails an
     * invoice; that one is left to the page.
     *
     * @param  array<string, string>  $metadata
     */
    private function failed(array $metadata): string
    {
        $draft = AgencyRegistrationDraft::query()->find((int) ($metadata['properx_draft_id'] ?? 0));
        $payment = $draft?->data['payment'] ?? null;

        if ($draft === null || ($payment['status'] ?? null) !== 'processing') {
            return 'agency_ignored';
        }

        $email = (string) ($payment['snapshot']['principal']['email'] ?? '');
        $token = $draft->issueToken();
        $draft->data = ['payment' => [
            'status' => 'failed',
            'message' => "Your bank didn't complete the payment, so nothing was charged. Please choose another bank account or a card.",
            'snapshot' => null,
        ] + $payment] + $draft->data;
        $draft->extend();
        $draft->save();

        if ($email !== '') {
            try {
                Notification::route('mail', $email)->notifyNow(new AgencyBankPaymentFailed(route('join.agency.continue', $token)));
            } catch (Throwable $e) {
                Log::warning('Agency enrollment: the failed bank payment email could not be sent', ['draft' => $draft->getKey(), 'error' => $e::class]);
            }
        }

        return 'agency_payment_failed';
    }

    /**
     * The subscription's metadata: on the subscription itself, or copied onto its invoices
     * (parent.subscription_details in newer API versions, subscription_details before).
     *
     * @return array<string, string>
     */
    public static function subscriptionMetadata(Event $event): array
    {
        $object = $event->data->object;

        $metadata = str_starts_with($event->type, 'customer.subscription.')
            ? ($object->metadata ?? null)
            : ($object->parent->subscription_details->metadata ?? $object->subscription_details->metadata ?? null);

        return $metadata instanceof StripeObject ? array_filter($metadata->toArray(), 'is_string') : [];
    }

    public static function subscriptionId(StripeObject $invoice): ?string
    {
        $id = $invoice->parent->subscription_details->subscription ?? $invoice->subscription ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * End of the period an invoice paid for: its lines' period end.
     */
    public static function paidThrough(StripeObject $invoice): ?Carbon
    {
        $ends = array_filter(array_map(fn ($line) => $line->period->end ?? null, $invoice->lines->data ?? []), fn ($end) => is_int($end) && $end > 0);

        return $ends === [] ? null : Carbon::createFromTimestamp(max($ends));
    }
}
