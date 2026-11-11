<?php

namespace App\Services\Stripe;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\MemberStatus;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AgencyBankPaymentFailed;
use App\Notifications\AgencyPaymentFailed;
use App\Services\Agencies\AgencyCheckout;
use App\Services\Agencies\AgencyEnrollment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Notifications\NovaNotification;
use Laravel\Nova\URL;
use Stripe\Event;
use Stripe\StripeObject;
use Stripe\Subscription;
use Throwable;

/**
 * Stripe events of agencies, picked out before any individual member is looked for, so an
 * agency's event never changes the principal's own profile (and the email fallback for
 * individuals never applies to an agency's customer). An agency is recognized by
 * agencies.stripe_customer_id or, before it exists, by the enrollment's metadata on the
 * subscription (properx_kind = agency_enrollment). Before the agency exists: the first payment
 * (invoice.paid enrolls it if the browser has not yet) and a failed bank payment of the enrollment
 * (it is open again). Once it exists (Agency spec 6, 7): invoice.paid renews it (paid_through_at,
 * active), invoice.payment_failed makes it past due (email to the principal, Nova notification to
 * staff), customer.subscription.updated brings the status, billed headcount, price and
 * cancel_at_period_end, customer.subscription.deleted makes it inactive. People follow: inactive
 * closes their access (MemberProfile::hasRestrictedMembership), past due keeps it. Repeated events
 * stop at stripe_events' id; an event older than agencies.payment_event_at changes no status, and a
 * failure older than the last payment (paid_event_at) changes nothing. The paid period moves only with
 * paid invoices, never with the subscription's own period (Stripe starts it before it is paid). The
 * reminder schedule (dunning) waits for the client.
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

        if ($agency === null) {
            return match ($event->type) {
                'invoice.paid' => $this->paid($event, null, $metadata, $customerId),
                'invoice.payment_failed' => $this->failed($metadata),
                // Before the agency exists (its subscription is created before it is paid).
                default => 'agency_ignored',
            };
        }

        $at = Carbon::createFromTimestamp($event->created);

        // The paid period only moves forward, whatever the order; the status follows the newest event.
        if ($event->type === 'invoice.paid') {
            $this->extend($agency, self::paidThrough($object));

            if ($agency->paid_event_at === null || $at->gt($agency->paid_event_at)) {
                $agency->forceFill(['paid_event_at' => $at])->saveQuietly();
            }
        }

        // A failure is told about unless a later payment settled it. Stripe sends the failure and the
        // subscription's own update within the same second or two, in either order.
        if ($event->type === 'invoice.payment_failed') {
            return $agency->paid_event_at !== null && $agency->paid_event_at->gt($at)
                ? 'agency_stale'
                : $this->overdue($agency, $object, $at);
        }

        if ($agency->payment_event_at !== null && $at->lt($agency->payment_event_at)) {
            return 'agency_stale';
        }

        return match ($event->type) {
            'invoice.paid' => $this->renewed($agency, $at),
            'customer.subscription.created', 'customer.subscription.updated' => $this->subscriptionChanged($agency, $object, $at),
            'customer.subscription.deleted' => $this->ended($agency, $at),
            default => 'agency_ignored',
        };
    }

    /**
     * invoice.paid of an enrolled agency (a renewal, or the first invoice after the page enrolled it).
     */
    private function renewed(Agency $agency, Carbon $at): string
    {
        $agency->forceFill(['payment_event_at' => $at, 'payment_url' => null])->saveQuietly();
        $this->setStatus($agency, AgencyStatus::Active);

        return 'agency_paid';
    }

    /**
     * invoice.payment_failed: past due. Everyone keeps access for now (the reminder schedule and the
     * 14 days of Agency spec 6 wait for the client); the principal is asked to pay, staff are told.
     */
    private function overdue(Agency $agency, StripeObject $invoice, Carbon $at): string
    {
        $payUrl = is_string($invoice->hosted_invoice_url ?? null) ? $invoice->hosted_invoice_url : null;
        // Told once per overdue invoice: Stripe's retries of the same invoice repeat its link.
        $told = $agency->status === AgencyStatus::PastDue && $agency->payment_url !== null && $agency->payment_url === $payUrl;

        $agency->forceFill(['payment_url' => $payUrl ?? $agency->payment_url])->saveQuietly();
        AuditLog::record('agency_payment_failed', $agency, details: array_filter(['invoice' => is_string($invoice->id ?? null) ? $invoice->id : null]));

        // A newer event (the subscription's update, or its end) already set the status.
        if ($agency->payment_event_at === null || $at->gte($agency->payment_event_at)) {
            $agency->forceFill(['payment_event_at' => $at])->saveQuietly();
            $this->setStatus($agency, AgencyStatus::PastDue);
        }

        if ($agency->status === AgencyStatus::PastDue && ! $told) {
            $this->tellAboutOverdue($agency);
        }

        return 'agency_past_due';
    }

    /**
     * customer.subscription.created / .updated: the status, how many people are billed, the price
     * (tier and rate type, by lookup key) and whether it ends at the period's end.
     */
    private function subscriptionChanged(Agency $agency, StripeObject $subscription, Carbon $at): string
    {
        $item = ($subscription->items->data ?? [])[0] ?? null;
        $lookupKey = is_string($item?->price?->lookup_key ?? null) ? $item->price->lookup_key : null;
        $priced = $lookupKey === null ? null : self::tierFor($lookupKey);

        $changes = array_filter([
            'billed_headcount' => is_int($item?->quantity ?? null) ? $item->quantity : null,
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'payment_event_at' => $at,
        ], fn ($value) => $value !== null);

        if ($priced !== null && ($agency->tier !== $priced['tier'] || $agency->rate_type !== $priced['rate_type'])) {
            AuditLog::record('agency_price_changed_in_stripe', $agency, details: [
                'from' => [$agency->tier?->value, $agency->rate_type?->value],
                'to' => [$priced['tier']->value, $priced['rate_type']->value],
            ]);
            $changes += ['tier' => $priced['tier'], 'rate_type' => $priced['rate_type']];
        }

        $agency->forceFill($changes)->saveQuietly();

        $status = match ((string) ($subscription->status ?? '')) {
            'active', 'trialing' => AgencyStatus::Active,
            'past_due', 'unpaid' => AgencyStatus::PastDue,
            'canceled', 'incomplete_expired' => AgencyStatus::Inactive,
            default => null, // incomplete, paused: no change
        };

        // No paid period from here: Stripe starts the next period (still "active") before its invoice
        // is paid. invoice.paid and the enrollment set paid_through_at.

        // The principal and staff hear about it from invoice.payment_failed, which brings the invoice's link.
        if ($status !== null) {
            $this->setStatus($agency, $status);
        }

        return 'agency_updated';
    }

    /**
     * customer.subscription.deleted: the membership has ended; everyone becomes inactive.
     */
    private function ended(Agency $agency, Carbon $at): string
    {
        $agency->forceFill(['payment_event_at' => $at, 'cancel_at_period_end' => false, 'payment_url' => null])->saveQuietly();
        $this->setStatus($agency, AgencyStatus::Inactive);

        return 'agency_inactive';
    }

    /**
     * The agency's status, and its people's (Agency spec 7, D2): inactive makes everyone inactive;
     * leaving inactive makes them active again. Audited with from / to.
     */
    private function setStatus(Agency $agency, AgencyStatus $to): bool
    {
        $from = $agency->status;

        if ($from === $to) {
            return false;
        }

        $agency->forceFill(['status' => $to])->saveQuietly();
        AuditLog::record('agency_payment_status_changed', $agency, details: ['from' => $from?->value, 'to' => $to->value]);

        if ($to === AgencyStatus::Inactive) {
            $agency->members()->update(['status' => MemberStatus::Inactive->value]);
        } elseif ($from === AgencyStatus::Inactive) {
            $agency->members()->update(['status' => MemberStatus::Active->value]);
        }

        return true;
    }

    private function extend(Agency $agency, ?CarbonInterface $paidThrough): void
    {
        if ($paidThrough !== null && ($agency->paid_through_at === null || $paidThrough->gt($agency->paid_through_at))) {
            $agency->forceFill(['paid_through_at' => $paidThrough])->saveQuietly();
        }
    }

    /**
     * The principal by email (a link to pay: Stripe's page of the invoice, else PAYMENT_PORTAL_URL),
     * and staff who see agencies by a Nova notification. No names or amounts.
     */
    private function tellAboutOverdue(Agency $agency): void
    {
        $email = $agency->principal?->email;
        $payUrl = $agency->payment_url ?: (config('portal.payments.payment_url') ?: null);

        if (is_string($email) && $email !== '') {
            try {
                // At once rather than queued: the invoice link would otherwise wait in the jobs table.
                $agency->principal->notifyNow(new AgencyPaymentFailed($payUrl));
            } catch (Throwable $e) {
                Log::warning('Agency payment failed email could not be sent', ['agency' => $agency->getKey(), 'error' => $e::class]);
            }
        }

        $staff = User::permission('agencies.view')->get()->reject(fn (User $user) => $user->isMember());

        foreach ($staff as $user) {
            $user->notify(NovaNotification::make()
                ->message("An agency's membership payment failed (agency #{$agency->getKey()}). It is past due.")
                ->action('Open the agency', URL::make('/resources/agencies/'.$agency->getKey()))
                ->icon('exclamation-circle')
                ->type('warning'));
        }
    }

    /**
     * The tier and rate type of an agency price's lookup key (config/agency.php).
     *
     * @return array{tier: AgencyTier, rate_type: RateType}|null
     */
    private static function tierFor(string $lookupKey): ?array
    {
        foreach (AgencyPriceCatalog::expected() as $expected) {
            if ($expected['lookupKey'] === $lookupKey) {
                return ['tier' => $expected['tier'], 'rate_type' => $expected['rateType']];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function paid(Event $event, ?Agency $agency, array $metadata, ?string $customerId): string
    {
        $object = $event->data->object;
        $paidThrough = self::paidThrough($object);

        $subscriptionId = self::subscriptionId($object);
        $draft = AgencyRegistrationDraft::query()->find((int) ($metadata['properx_draft_id'] ?? 0));

        if ($subscriptionId === null || $customerId === null || ! is_array($draft?->data['payment']['snapshot'] ?? null)) {
            Log::warning('Agency enrollment paid but its draft is gone', ['event' => $event->id, 'subscription' => $subscriptionId]);

            return 'agency_unmatched';
        }

        // Also a bank payment that cleared after the page was left on "processing": the principal's
        // welcome email (AgencyEnrollment::complete) tells them.
        $result = $this->enrollment->complete($draft, $subscriptionId, $customerId, $paidThrough);

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
