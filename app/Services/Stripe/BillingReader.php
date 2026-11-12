<?php

namespace App\Services\Stripe;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * The billing page's data from Stripe (Agency spec 6, G5): a customer's subscription, its payment
 * method and its invoices, read live with STRIPE_SECRET; and a Customer Portal session for
 * "Manage payment method". Nothing is stored. Stripe's own messages are never shown or logged
 * (they can repeat card or bank details): the log gets the error class and Stripe's code.
 */
class BillingReader
{
    /** How many invoices the page lists. */
    public const INVOICES = 12;

    public static function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * @return array{subscription: ?array{status: string, items: list<array{name: ?string, quantity: int, unit_amount: ?int, interval: ?string}>, amount: int, currency: string, renews_at: ?Carbon, cancel_at_period_end: bool}, payment_method: ?array{kind: string, label: string, detail: ?string}, invoices: list<array{number: string, date: Carbon, amount: int, currency: string, status: string, pdf: ?string, url: ?string}>}
     *
     * @throws BillingUnavailableException
     */
    public function read(string $customerId, ?string $subscriptionId): array
    {
        try {
            $stripe = $this->client();
            $subscription = $this->subscription($stripe, $customerId, $subscriptionId);
            $paymentMethod = $subscription?->default_payment_method ?? null;

            if (! is_object($paymentMethod)) {
                $customer = $stripe->customers->retrieve($customerId, ['expand' => ['invoice_settings.default_payment_method']]);
                $paymentMethod = $customer->invoice_settings->default_payment_method ?? null;
            }

            $invoices = $stripe->invoices->all(['customer' => $customerId, 'limit' => self::INVOICES]);
        } catch (ApiErrorException $e) {
            Log::warning('Billing page: Stripe could not be read', ['error' => $e::class, 'code' => $e->getStripeCode()]);

            throw new BillingUnavailableException(previous: $e);
        }

        return [
            'subscription' => $subscription === null ? null : self::describeSubscription($subscription),
            'payment_method' => is_object($paymentMethod) ? self::describePaymentMethod($paymentMethod) : null,
            'invoices' => array_values(array_filter(array_map(self::describeInvoice(...), $invoices->data ?? []))),
        ];
    }

    /**
     * Stripe's Customer Portal for this customer, back to $returnUrl. Its configuration (payment
     * method and invoices only; no cancelling or plan changes) is set in the Stripe account or
     * named by STRIPE_PORTAL_CONFIGURATION.
     *
     * @throws BillingUnavailableException
     */
    public function portalUrl(string $customerId, string $returnUrl): string
    {
        $params = ['customer' => $customerId, 'return_url' => $returnUrl];

        if (filled(config('services.stripe.portal_configuration'))) {
            $params['configuration'] = (string) config('services.stripe.portal_configuration');
        }

        try {
            return (string) $this->client()->billingPortal->sessions->create($params)->url;
        } catch (ApiErrorException $e) {
            Log::warning('Billing page: Stripe refused a Customer Portal session', ['error' => $e::class, 'code' => $e->getStripeCode()]);

            throw new BillingUnavailableException(previous: $e);
        }
    }

    /**
     * The billing email and name on the agency's customer (invoices and receipts go there).
     *
     * @param  array{email?: string, name?: string}  $changes
     */
    public function updateCustomer(string $customerId, array $changes): void
    {
        $this->client()->customers->update($customerId, $changes);
    }

    private function subscription(StripeClient $stripe, string $customerId, ?string $subscriptionId): ?object
    {
        $expand = ['default_payment_method', 'items.data.price.product'];

        if ($subscriptionId !== null && $subscriptionId !== '') {
            $subscription = $stripe->subscriptions->retrieve($subscriptionId, ['expand' => $expand]);

            // Someone else's subscription is never shown, whatever was stored.
            return $subscription->customer === $customerId ? $subscription : null;
        }

        // Individual members linked by customer only: their current subscription, if any.
        $list = $stripe->subscriptions->all(['customer' => $customerId, 'limit' => 1, 'expand' => array_map(fn ($path) => 'data.'.$path, $expand)]);

        return $list->data[0] ?? null;
    }

    /**
     * @return array{status: string, items: list<array{name: ?string, quantity: int, unit_amount: ?int, interval: ?string}>, amount: int, currency: string, renews_at: ?Carbon, cancel_at_period_end: bool}
     */
    private static function describeSubscription(object $subscription): array
    {
        $items = [];
        $amount = 0;
        $ends = [];

        foreach ($subscription->items->data ?? [] as $item) {
            $price = $item->price ?? null;
            $quantity = (int) ($item->quantity ?? 1);
            $unit = is_int($price->unit_amount ?? null) ? $price->unit_amount : null;
            $product = $price->product ?? null;

            $items[] = [
                'name' => is_object($product) ? ($product->name ?? null) : null,
                'quantity' => $quantity,
                'unit_amount' => $unit,
                'interval' => $price->recurring->interval ?? null,
            ];
            $amount += (int) $unit * $quantity;

            if (is_int($item->current_period_end ?? null)) {
                $ends[] = $item->current_period_end;
            }
        }

        // Older API versions keep the period on the subscription itself.
        if (is_int($subscription->current_period_end ?? null)) {
            $ends[] = $subscription->current_period_end;
        }

        return [
            'status' => (string) ($subscription->status ?? ''),
            'items' => $items,
            'amount' => $amount,
            'currency' => (string) ($subscription->currency ?? 'usd'),
            'renews_at' => $ends === [] ? null : Carbon::createFromTimestamp(max($ends)),
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
        ];
    }

    /**
     * @return array{kind: string, label: string, detail: ?string}
     */
    private static function describePaymentMethod(object $method): array
    {
        if (($method->type ?? null) === 'us_bank_account') {
            $bank = $method->us_bank_account ?? null;

            return [
                'kind' => 'bank',
                'label' => trim(($bank->bank_name ?? 'Bank account').' ending '.($bank->last4 ?? '····')),
                'detail' => ucfirst((string) ($bank->account_type ?? 'bank')).' account',
            ];
        }

        $card = $method->card ?? null;
        $brand = (string) ($card->display_brand ?? $card->brand ?? 'Card');

        return [
            'kind' => 'card',
            'label' => ucwords(str_replace('_', ' ', $brand)).' ending '.($card->last4 ?? '····'),
            'detail' => isset($card->exp_month, $card->exp_year) ? sprintf('Expires %02d / %d', $card->exp_month, $card->exp_year) : null,
        ];
    }

    /**
     * Drafts are not invoices yet and are left out.
     *
     * @return array{number: string, date: Carbon, amount: int, currency: string, status: string, pdf: ?string, url: ?string}|null
     */
    private static function describeInvoice(object $invoice): ?array
    {
        if (($invoice->status ?? 'draft') === 'draft') {
            return null;
        }

        $https = fn ($url) => is_string($url) && str_starts_with($url, 'https://') ? $url : null;

        return [
            'number' => (string) ($invoice->number ?? $invoice->id),
            'date' => Carbon::createFromTimestamp((int) ($invoice->status_transitions->finalized_at ?? $invoice->created)),
            'amount' => (int) ($invoice->total ?? 0),
            'currency' => (string) ($invoice->currency ?? 'usd'),
            'status' => (string) $invoice->status,
            'pdf' => $https($invoice->invoice_pdf ?? null),
            'url' => $https($invoice->hosted_invoice_url ?? null),
        ];
    }

    private function client(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
