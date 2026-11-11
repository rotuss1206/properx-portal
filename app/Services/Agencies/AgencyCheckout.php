<?php

namespace App\Services\Agencies;

use App\Models\AgencyRegistrationDraft;
use App\Services\Stripe\AgencyPriceCatalog;
use App\Services\Stripe\MissingStripePriceException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * The agency enrollment's payment in Stripe (Agency spec 6), for /join/agency:
 * reads what a Payment Element confirmation token says about the payment method (so the
 * credit card surcharge is known before paying), then creates the agency's customer and an
 * annual subscription (quantity = headcount, payment_behavior=default_incomplete) whose first
 * invoice the browser confirms with that token. The surcharge is a line of the first invoice
 * only. Requests carry idempotency keys from the draft and its contents, so a repeated click
 * returns the same customer and subscription. No member data goes into metadata.
 */
class AgencyCheckout
{
    public const METADATA_KIND = 'agency_enrollment';

    public function __construct(private AgencyPriceCatalog $catalog) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));
    }

    /**
     * @return array{type: string, funding: ?string, label: string}
     *
     * @throws CheckoutException
     */
    public function paymentMethod(string $confirmationTokenId): array
    {
        if (preg_match('/^ctoken_[A-Za-z0-9_]+$/', $confirmationTokenId) !== 1) {
            throw new CheckoutException(CheckoutException::GENERIC);
        }

        try {
            $token = $this->stripe()->confirmationTokens->retrieve($confirmationTokenId);
        } catch (ApiErrorException $e) {
            $this->log('confirmation token', $e);

            throw CheckoutException::fromStripe($e);
        }

        $preview = $token->payment_method_preview ?? null;

        if (is_int($token->expires_at ?? null) && $token->expires_at <= now()->getTimestamp()) {
            throw new CheckoutException('This took a while, so please check the payment details again.');
        }

        return match ($preview?->type) {
            'card' => [
                'type' => 'card',
                'funding' => is_string($preview->card->funding ?? null) ? $preview->card->funding : null,
                'label' => trim(Str::title((string) ($preview->card->display_brand ?? $preview->card->brand ?? 'Card')).' '.($preview->card->funding ?? '').' card ···· '.($preview->card->last4 ?? '')),
            ],
            'us_bank_account' => [
                'type' => 'us_bank_account',
                'funding' => null,
                'label' => trim(($preview->us_bank_account->bank_name ?? 'Bank account').' ···· '.($preview->us_bank_account->last4 ?? '')),
            ],
            default => throw new CheckoutException('Please pay by card or from a US bank account.'),
        };
    }

    /**
     * The customer and the subscription for this payment: the ones started earlier for the same
     * contents, or new ones. An unpaid subscription started for other contents is canceled.
     *
     * @param  array<string, mixed>  $snapshot  what is being paid for (Wizard::checkoutSnapshot)
     * @param  array<string, mixed>  $earlier  the draft's earlier payment: customer, subscription, content
     * @return array{customer: string, subscription: string, client_secret: string, payment_intent: string}
     *
     * @throws CheckoutException
     */
    public function start(AgencyRegistrationDraft $draft, array $snapshot, AgencyQuote $quote, string $contentHash, array $earlier = []): array
    {
        $stripe = $this->stripe();

        try {
            $customerId = is_string($earlier['customer'] ?? null) ? $earlier['customer'] : $this->createCustomer($draft, $snapshot);

            if (is_string($earlier['subscription'] ?? null)) {
                $previous = $stripe->subscriptions->retrieve($earlier['subscription'], ['expand' => ['latest_invoice.confirmation_secret']]);

                if ($previous->status === 'incomplete' && ($earlier['content'] ?? null) === $contentHash) {
                    return $this->started($customerId, $previous);
                }

                if ($previous->status === 'incomplete') {
                    $stripe->subscriptions->cancel($previous->id);
                }
            }

            $item = $quote->partnerRateApplied
                // The partner rate has no price of its own: the same product at that amount.
                ? ['price_data' => ['currency' => config('agency.currency'), 'product' => $this->catalog->agencyProductId(), 'unit_amount' => $quote->rate, 'recurring' => ['interval' => 'year']], 'quantity' => $quote->headcount]
                : ['price' => $this->catalog->priceId($quote->tier, $quote->rateType), 'quantity' => $quote->headcount];

            $params = [
                'customer' => $customerId,
                'items' => [$item],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription', 'payment_method_types' => ['card', 'us_bank_account']],
                'metadata' => self::metadata($draft) + ['properx_tier' => $quote->tier->value, 'properx_rate_type' => $quote->rateType->value],
                'expand' => ['latest_invoice.confirmation_secret'],
            ];

            if ($quote->surcharge > 0) {
                $params['add_invoice_items'] = [[
                    'price_data' => ['currency' => config('agency.currency'), 'product' => $this->catalog->surchargeProductId(), 'unit_amount' => $quote->surcharge],
                    'quantity' => 1,
                ]];
            }

            $subscription = $stripe->subscriptions->create($params, ['idempotency_key' => "properx-agency-subscription-{$draft->getKey()}-{$contentHash}"]);
        } catch (ApiErrorException $e) {
            $this->log('subscription', $e);

            throw CheckoutException::fromStripe($e);
        } catch (MissingStripePriceException $e) {
            Log::error('Agency enrollment cannot be paid: Stripe prices are missing', ['draft' => $draft->getKey()]);

            throw new CheckoutException(CheckoutException::GENERIC, previous: $e);
        }

        return $this->started($customerId, $subscription);
    }

    /**
     * @throws CheckoutException
     */
    public function paymentIntent(string $id): PaymentIntent
    {
        if (preg_match('/^pi_[A-Za-z0-9_]+$/', $id) !== 1) {
            throw new CheckoutException(CheckoutException::GENERIC);
        }

        try {
            return $this->stripe()->paymentIntents->retrieve($id);
        } catch (ApiErrorException $e) {
            $this->log('payment intent', $e);

            throw CheckoutException::fromStripe($e);
        }
    }

    /**
     * @throws CheckoutException
     */
    public function subscription(string $id): Subscription
    {
        try {
            return $this->stripe()->subscriptions->retrieve($id);
        } catch (ApiErrorException $e) {
            $this->log('subscription', $e);

            throw CheckoutException::fromStripe($e);
        }
    }

    /**
     * End of the paid year: the items' current_period_end (newer API versions moved it there).
     */
    public static function periodEnd(Subscription $subscription): ?CarbonInterface
    {
        $ends = array_filter(
            [...array_map(fn ($item) => $item->current_period_end ?? null, $subscription->items->data ?? []), $subscription->current_period_end ?? null],
            fn ($end) => is_int($end) && $end > 0,
        );

        return $ends === [] ? null : Carbon::createFromTimestamp(max($ends));
    }

    /**
     * @return array<string, string>
     */
    public static function metadata(AgencyRegistrationDraft $draft): array
    {
        return ['properx_kind' => self::METADATA_KIND, 'properx_draft_id' => (string) $draft->getKey()];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function createCustomer(AgencyRegistrationDraft $draft, array $snapshot): string
    {
        $name = (string) ($snapshot['agency']['legal_name'] ?? '');
        $email = (string) ($snapshot['agency']['billing_email'] ?? '');

        $customer = $this->stripe()->customers->create(
            ['name' => $name, 'email' => $email, 'metadata' => self::metadata($draft)],
            ['idempotency_key' => 'properx-agency-customer-'.$draft->getKey().'-'.substr(hash('sha256', $name."\n".$email), 0, 16)],
        );

        return (string) $customer->id;
    }

    /**
     * @return array{customer: string, subscription: string, client_secret: string, payment_intent: string}
     */
    private function started(string $customerId, Subscription $subscription): array
    {
        $secret = $subscription->latest_invoice->confirmation_secret->client_secret ?? null;

        if (! is_string($secret) || ! str_contains($secret, '_secret_')) {
            Log::error('Agency subscription has no payment to confirm', ['subscription' => $subscription->id, 'status' => $subscription->status]);

            throw new CheckoutException(CheckoutException::GENERIC);
        }

        return [
            'customer' => $customerId,
            'subscription' => (string) $subscription->id,
            'client_secret' => $secret,
            'payment_intent' => Str::before($secret, '_secret_'),
        ];
    }

    private function stripe(): StripeClient
    {
        return $this->catalog->client();
    }

    private function log(string $what, ApiErrorException $e): void
    {
        // The class and Stripe's code only: messages can repeat what was typed.
        Log::warning("Agency checkout: Stripe refused the {$what}", ['error' => $e::class, 'code' => $e->getStripeCode()]);
    }
}
