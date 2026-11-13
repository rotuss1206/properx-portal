<?php

namespace App\Services\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Jobs\SendAgencyInvitation;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AgencyPeopleAdded;
use App\Notifications\Member\AgencyEndingAtRenewal;
use App\Notifications\Member\AgencyRemovalScheduled;
use App\Services\Stripe\AgencyPriceCatalog;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * An enrolled agency's principal adds and removes people (Agency spec 8.1, 4; G3, G4), with no
 * credits or refunds (Agency spec 2):
 *  - Adding: the new people pay their prorated share of the rest of the paid period at once, with
 *    the agency's saved payment method, on an invoice of its own (plus the credit card surcharge
 *    where it applies). If the headcount reaches a higher tier, everyone's rate drops to it from
 *    the next renewal; nobody is credited now. Nothing changes unless the payment went through.
 *  - Removing: the person keeps access until the next renewal, then leaves the agency (inactive,
 *    offered individual membership). Stripe is told at once to bill the next renewal for the
 *    people who stay, at their tier (it may go down; the rate type stays), with no proration. Fewer
 *    than two left: the subscription ends at the period's end instead, and everyone is told.
 */
class AgencyRosterChanges
{
    /** Why a person is removed; a key goes to the audit log, never free text. */
    public const REASONS = [
        'left_agency' => 'Left the agency',
        'role_changed' => 'Role changed; no longer needs membership',
        'duplicate' => 'Added twice or by mistake',
        'other' => 'Other',
    ];

    public function __construct(
        private AgencyPriceCatalog $catalog,
        private AgencyPricingService $pricing,
        private AgencyEnrollment $enrollment,
    ) {}

    // Adding

    /**
     * Stripe's preview of the change (Agency spec 4, 8.1): the quantity up, everyone at the price of
     * the tier the headcount reaches, all prorated from now to the end of the paid period.
     *
     * @throws RosterChangeException when the agency cannot add people now
     */
    public function quote(Agency $agency, int $added, ?int $prorationDate = null): AgencyAdditionQuote
    {
        if ($added < 1) {
            throw new RosterChangeException('Add at least one person first.');
        }

        if ($agency->status !== AgencyStatus::Active || blank($agency->stripe_subscription_id) || $agency->paid_through_at === null || ! $agency->paid_through_at->isFuture()) {
            throw new RosterChangeException('People can be added while the membership is active and paid. Please contact ProPerX.');
        }

        if ($agency->tier === null || $agency->rate_type === null) {
            throw new RosterChangeException('The agency has no tier yet. Please contact ProPerX.');
        }

        $method = $this->paymentMethod($agency);

        if ($method === null) {
            throw new RosterChangeException('There is no payment method on file. Add one under Billing (Manage payment method), then come back.');
        }

        $before = $agency->continuingHeadcount();
        $after = $before + $added;
        $tierAfter = AgencyTier::forHeadcount($after);
        // Adding never moves an agency down a tier.
        $tierAfter = $tierAfter->minHeadcount() < $agency->tier->minHeadcount() ? $agency->tier : $tierAfter;
        $prorationDate ??= now()->getTimestamp();
        $itemId = $this->subscriptionItemId($agency);

        try {
            $preview = $this->stripe()->invoices->createPreview([
                'customer' => $agency->stripe_customer_id,
                'subscription' => $agency->stripe_subscription_id,
                'subscription_details' => [
                    'items' => [['id' => $itemId, 'quantity' => $after] + $this->priceParams($agency, $tierAfter)],
                    'proration_behavior' => 'always_invoice',
                    'proration_date' => $prorationDate,
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('Adding people: Stripe could not preview the change', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);

            throw new RosterChangeException('We can’t reach Stripe right now. Please try again in a few minutes.');
        }

        $lines = [];

        foreach ($preview->lines->data ?? [] as $line) {
            $proration = (bool) ($line->proration ?? $line->parent->subscription_item_details->proration ?? false);

            if ($proration) {
                $lines[] = ['description' => (string) ($line->description ?? ''), 'amount' => (int) ($line->amount ?? 0)];
            }
        }

        $net = array_sum(array_column($lines, 'amount'));
        // No credits (Agency spec 2): a change that would come out below zero charges nothing.
        $noCharge = $net <= 0;
        $subtotal = max(0, $net);
        $surcharge = $noCharge ? 0 : $this->pricing->surcharge($subtotal, $method['funding'], $agency->legalState());

        return new AgencyAdditionQuote(
            added: $added,
            headcountBefore: $before,
            headcountAfter: $after,
            tierBefore: $agency->tier,
            tierAfter: $tierAfter,
            rate: $this->rateFor($agency, $tierAfter),
            periodEnds: $agency->paid_through_at->copy(),
            prorationDate: $prorationDate,
            lines: $lines,
            subtotal: $subtotal,
            noCharge: $noCharge,
            surcharge: $surcharge,
            total: $subtotal + $surcharge,
            paymentMethod: $method['label'],
            paymentMethodId: $method['id'],
            subscriptionItemId: $itemId,
        );
    }

    /**
     * Makes the previewed change and pays it at once with the saved payment method, then creates
     * the people, sends their invitations, emails the principal a receipt and audits it. The
     * change uses the preview's proration date, so the amount is the one shown; a different one
     * (the agency changed meanwhile) is shown again instead.
     *
     * @param  list<array<string, string>>  $rows  PeopleForm rows, already checked
     * @return list<User> the people created
     *
     * @throws CheckoutException when the payment did not go through (nothing changed)
     * @throws RosterChangeException when the agency cannot add people now
     */
    public function addPeople(Agency $agency, array $rows, User $by, int $expectedTotal, int $prorationDate): array
    {
        return Cache::lock('agency-roster:'.$agency->getKey(), 60)->block(20, function () use ($agency, $rows, $by, $expectedTotal, $prorationDate) {
            $agency->refresh();
            $quote = $this->quote($agency, count($rows), $prorationDate);

            if ($quote->total !== $expectedTotal) {
                throw new RosterChangeException('The amount is now '.Money::exact($quote->total).'. Please check it and confirm again.');
            }

            $invoiceId = $this->charge($agency, $quote, $rows);

            $created = DB::transaction(function () use ($agency, $rows) {
                $people = [];

                foreach ($rows as $row) {
                    $user = $this->enrollment->createPerson($agency, $row + ['phone' => null, 'principal' => false]);

                    if ($user !== null) {
                        $people[] = $user;
                    }
                }

                $agency->syncHeadcount();

                return $people;
            });

            $agency->forceFill(['tier' => $quote->tierAfter, 'billed_headcount' => $quote->headcountAfter])->saveQuietly();

            AuditLog::record('agency_people_added', $agency, $by, array_filter([
                'people' => count($created),
                'not_created' => count($rows) - count($created) ?: null,
                'amount' => $quote->subtotal,
                'surcharge' => $quote->surcharge,
                'tier_from' => $quote->tierBefore->value,
                'tier_to' => $quote->tierChanges() ? $quote->tierAfter->value : null,
                'invoice' => $invoiceId,
            ], fn ($value) => $value !== null));

            foreach ($created as $user) {
                SendAgencyInvitation::dispatch($user->memberProfile->getKey(), null);
            }

            $by->notify(new AgencyPeopleAdded(count($created), $quote->tierAfter->value, $quote->tierChanges(), $quote->rate, $quote->lines, $quote->subtotal, $quote->surcharge, $quote->total, $quote->periodEnds->toDateString(), $quote->paymentMethod));

            return $created;
        });
    }

    /**
     * The subscription change itself, invoiced and paid at once (always_invoice) with the saved
     * method; error_if_incomplete leaves the subscription as it was when the payment does not go
     * through. A change that would credit the agency is made without proration (nothing charged,
     * nothing credited; the new price from the next renewal).
     *
     * @param  list<array<string, string>>  $rows
     *
     * @throws CheckoutException
     */
    private function charge(Agency $agency, AgencyAdditionQuote $quote, array $rows): ?string
    {
        $key = 'properx-agency-add-'.$agency->getKey().'-'.substr(hash('sha256', json_encode([array_column($rows, 'email'), $quote->total, $quote->prorationDate])), 0, 20);
        $params = [
            'items' => [['id' => $quote->subscriptionItemId, 'quantity' => $quote->headcountAfter] + $this->priceParams($agency, $quote->tierAfter)],
            'expand' => ['latest_invoice'],
        ];

        if ($quote->noCharge) {
            $params['proration_behavior'] = 'none';
        } else {
            $params += [
                'proration_behavior' => 'always_invoice',
                'proration_date' => $quote->prorationDate,
                'payment_behavior' => 'error_if_incomplete',
                'default_payment_method' => $quote->paymentMethodId,
            ];

            if ($quote->surcharge > 0) {
                $params['add_invoice_items'] = [[
                    'price_data' => ['currency' => config('agency.currency'), 'product' => $this->catalog->surchargeProductId(), 'unit_amount' => $quote->surcharge],
                    'quantity' => 1,
                ]];
            }
        }

        try {
            $subscription = $this->stripe()->subscriptions->update($agency->stripe_subscription_id, $params, ['idempotency_key' => $key]);
        } catch (ApiErrorException $e) {
            Log::warning('Adding people: the payment did not go through', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);

            throw CheckoutException::fromStripe($e);
        }

        $invoice = $subscription->latest_invoice ?? null;

        return $quote->noCharge ? null : (is_object($invoice) ? (string) $invoice->id : (is_string($invoice) ? $invoice : null));
    }

    /**
     * The tier's price for the agency's rate type, or the partner rate on the agency product.
     *
     * @return array<string, mixed>
     */
    private function priceParams(Agency $agency, AgencyTier $tier): array
    {
        $tierRate = $tier->rate($agency->rate_type);
        $rate = $this->rateFor($agency, $tier);

        return $rate < $tierRate
            ? ['price_data' => ['currency' => config('agency.currency'), 'product' => $this->catalog->agencyProductId(), 'unit_amount' => $rate, 'recurring' => ['interval' => 'year']]]
            : ['price' => $this->catalog->priceId($tier, $agency->rate_type)];
    }

    private function rateFor(Agency $agency, AgencyTier $tier): int
    {
        $tierRate = $tier->rate($agency->rate_type);

        return $agency->partnerCode?->isValid(now()) ? min($tierRate, (int) config('agency.partner_rate')) : $tierRate;
    }

    /**
     * @throws RosterChangeException
     */
    private function subscriptionItemId(Agency $agency): string
    {
        try {
            $itemId = $this->stripe()->subscriptions->retrieve($agency->stripe_subscription_id)->items->data[0]->id ?? null;
        } catch (ApiErrorException $e) {
            Log::warning('Adding people: the subscription could not be read', ['agency' => $agency->getKey(), 'error' => $e::class]);
            $itemId = null;
        }

        return $itemId ?? throw new RosterChangeException('We can’t reach Stripe right now. Please try again in a few minutes.');
    }

    // Removing

    /**
     * @throws RosterChangeException
     */
    public function scheduleRemoval(Agency $agency, MemberProfile $person, string $reason, User $by): void
    {
        if ($person->agency_id !== $agency->getKey() || $person->user_id === $agency->principal_user_id) {
            throw new RosterChangeException('This person cannot be removed here. The principal is changed by ProPerX.');
        }

        if ($person->removal_effective_at !== null) {
            throw new RosterChangeException('This person is already leaving at the next renewal.');
        }

        if (! array_key_exists($reason, self::REASONS)) {
            throw new RosterChangeException('Choose a reason.');
        }

        $effective = $agency->paid_through_at && $agency->paid_through_at->isFuture() ? $agency->paid_through_at->copy() : now();
        $wasContinuing = $agency->continuingHeadcount();

        $person->forceFill(['removal_effective_at' => $effective, 'removal_reason' => $reason, 'removal_scheduled_at' => now()])->save();
        AuditLog::record('agency_member_removal_scheduled', $person, $by, ['agency_id' => $agency->getKey(), 'reason' => $reason, 'effective_on' => $effective->toDateString()]);

        $person->user?->notify(new AgencyRemovalScheduled($effective->toDateString()));

        $this->syncRenewal($agency);

        // From now on the agency will not renew: everyone still on it hears so, once.
        $minimum = (int) config('agency.minimum_headcount');

        if ($wasContinuing >= $minimum && $agency->continuingHeadcount() < $minimum) {
            $agency->continuingMembers()->with('user')->get()->each(fn (MemberProfile $stays) => $stays->user?->notify(new AgencyEndingAtRenewal($effective->toDateString())));
        }
    }

    public function cancelRemoval(Agency $agency, MemberProfile $person, User $by): void
    {
        if ($person->agency_id !== $agency->getKey() || $person->removal_effective_at === null) {
            throw new RosterChangeException('Nothing to cancel for this person.');
        }

        if ($person->removal_effective_at->isPast()) {
            throw new RosterChangeException('The removal has already taken effect.');
        }

        $person->forceFill(['removal_effective_at' => null, 'removal_reason' => null, 'removal_scheduled_at' => null])->save();
        AuditLog::record('agency_member_removal_canceled', $person, $by, ['agency_id' => $agency->getKey()]);

        $this->syncRenewal($agency);
    }

    /**
     * Removals whose date has come: the person leaves the agency, inactive, with no paid membership
     * of their own (they were offered individual membership when it was scheduled).
     */
    public function applyRemoval(MemberProfile $person): void
    {
        if ($person->agency_id === null || $person->removal_effective_at === null || $person->removal_effective_at->isFuture()) {
            return;
        }

        $agency = $person->agency;

        DB::transaction(function () use ($person) {
            // MemberProfileObserver audits agency_member_removed.
            $person->forceFill([
                'agency_id' => null,
                'agency_role' => null,
                'status' => MemberStatus::Inactive,
                'payment_status' => PaymentStatus::Canceled,
                'payment_status_updated_at' => now(),
            ])->save();
        });

        $agency?->syncHeadcount();
    }

    /**
     * Tells Stripe who the next renewal bills: the people who stay, at their tier (which may go
     * down; the rate type stays), with no proration now. Fewer than the minimum: the subscription
     * ends with the period instead (and goes on again if removals are canceled).
     */
    public function syncRenewal(Agency $agency): void
    {
        if (blank($agency->stripe_subscription_id) || $agency->rate_type === null) {
            return;
        }

        $continuing = $agency->continuingHeadcount();
        $minimum = (int) config('agency.minimum_headcount');

        try {
            if ($continuing < $minimum) {
                $this->stripe()->subscriptions->update($agency->stripe_subscription_id, ['cancel_at_period_end' => true]);
                $agency->forceFill(['cancel_at_period_end' => true])->saveQuietly();

                return;
            }

            $this->updateSubscription($agency, $continuing, AgencyTier::forHeadcount($continuing), endsWithPeriod: false);
            $agency->forceFill(['cancel_at_period_end' => false])->saveQuietly();
        } catch (ApiErrorException $e) {
            // The principal's change stands; staff fix Stripe by hand.
            Log::error('Agency renewal quantity could not be set in Stripe', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);
            report($e);
        }
    }

    /**
     * The subscription's item: quantity and price (the tier's price, or the partner rate on the
     * agency product), with no proration.
     */
    private function updateSubscription(Agency $agency, int $quantity, AgencyTier $tier, ?bool $endsWithPeriod = null): void
    {
        $stripe = $this->stripe();

        try {
            $subscription = $stripe->subscriptions->retrieve($agency->stripe_subscription_id);
            $itemId = $subscription->items->data[0]->id ?? null;

            if ($itemId === null) {
                return;
            }

            $price = $this->priceParams($agency, $tier);

            $stripe->subscriptions->update($agency->stripe_subscription_id, array_filter([
                'items' => [['id' => $itemId, 'quantity' => $quantity] + $price],
                'proration_behavior' => 'none',
                'cancel_at_period_end' => $endsWithPeriod,
            ], fn ($value) => $value !== null));
        } catch (ApiErrorException $e) {
            if ($endsWithPeriod !== null) {
                throw $e;
            }

            // Paid and created already: staff set the quantity in Stripe by hand.
            Log::error('Agency subscription could not be updated after adding people', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);
            report($e);
        }
    }

    /**
     * The agency's saved payment method: the subscription's, else the customer's default.
     *
     * @return array{id: string, funding: ?string, label: string}|null
     */
    public function paymentMethod(Agency $agency): ?array
    {
        try {
            $stripe = $this->stripe();
            /** @var Subscription $subscription */
            $subscription = $stripe->subscriptions->retrieve($agency->stripe_subscription_id, ['expand' => ['default_payment_method']]);
            $method = $subscription->default_payment_method ?? null;

            if (! is_object($method)) {
                $customer = $stripe->customers->retrieve($agency->stripe_customer_id, ['expand' => ['invoice_settings.default_payment_method']]);
                $method = $customer->invoice_settings->default_payment_method ?? null;
            }
        } catch (ApiErrorException $e) {
            Log::warning('Adding people: the payment method could not be read', ['agency' => $agency->getKey(), 'error' => $e::class]);

            throw new RosterChangeException('We can’t reach Stripe right now. Please try again in a few minutes.');
        }

        if (! is_object($method)) {
            return null;
        }

        if (($method->type ?? null) === 'us_bank_account') {
            return ['id' => (string) $method->id, 'funding' => null, 'label' => trim(($method->us_bank_account->bank_name ?? 'Bank account').' ending '.($method->us_bank_account->last4 ?? ''))];
        }

        $card = $method->card ?? null;
        $funding = is_string($card->funding ?? null) ? $card->funding : null;

        return [
            'id' => (string) $method->id,
            'funding' => $funding,
            'label' => implode(' ', array_filter([ucwords(str_replace('_', ' ', (string) ($card->display_brand ?? $card->brand ?? 'Card'))), in_array($funding, ['credit', 'debit', 'prepaid'], true) ? $funding : null, 'card ending', $card->last4 ?? null])),
        ];
    }

    private function stripe(): StripeClient
    {
        return $this->catalog->client();
    }

    /**
     * For the confirmation step: who is new, by kind.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{producers: int, employees: int}
     */
    public static function kinds(array $rows): array
    {
        $employees = count(array_filter($rows, fn (array $row) => $row['type'] === PersonType::AgencyEmployee->value));

        return ['producers' => count($rows) - $employees, 'employees' => $employees];
    }

    /**
     * When the removals of an agency take effect (its renewal date), for the page.
     */
    public static function effectiveDate(Agency $agency): Carbon
    {
        return $agency->paid_through_at && $agency->paid_through_at->isFuture() ? $agency->paid_through_at->copy() : now();
    }
}
