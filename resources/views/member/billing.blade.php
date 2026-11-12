{{--
    Billing (mockup Billing.jpg): the plan, the payment method and the invoices of the Stripe
    customer this person may see (App\Member\BillingPage): the agency's for its principal, their
    own for an individual member. Other agency people only learn who pays; a member without a
    linked customer gets the payment page. Read live from Stripe, nothing stored.
--}}
@php
    use App\Member\BillingPage;
    use App\Support\Money;

    $subscription = $stripe['subscription'] ?? null;
    $item = $subscription['items'][0] ?? null;
    $isAgency = $billing->kind === BillingPage::AGENCY;
    $unit = $item['unit_amount'] ?? $plan['rate'];
    $total = $subscription['amount'] ?? $plan['total'];
    $renewsAt = $subscription['renews_at'] ?? $plan['renews_at'];
    $interval = ($item['interval'] ?? 'year') === 'month' ? 'month' : 'year';
    $statusVariant = ['paid' => 'success', 'open' => 'warning', 'uncollectible' => 'danger', 'void' => 'neutral'];
@endphp
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-billing">
        <p class="ppx-docs__lead">
            @if (in_array($billing->kind, [BillingPage::AGENCY, BillingPage::INDIVIDUAL], true))
                Manage your membership plan, payment method and invoices.
            @else
                Your membership and how it is paid.
            @endif
        </p>

        @if (session('billing_error'))
            <p class="ppx-notice ppx-notice--danger" role="alert">{{ session('billing_error') }}</p>
        @endif

        @if ($billing->kind === BillingPage::PAID_BY_AGENCY)
            <x-ppx.card title="Your membership" heading="h2">
                <x-ppx.empty-state icon="billing" :title="'Your membership is paid by '.($billing->agency()?->legal_name ?? 'your agency')" text="Your agency’s principal manages the payment and the invoices. For questions about your membership, contact them or ProPerX." />
            </x-ppx.card>
        @elseif ($billing->kind === BillingPage::NOT_LINKED)
            <x-ppx.card title="Your membership" heading="h2">
                <x-ppx.empty-state icon="billing" title="Your payment details are not linked to the portal yet" text="Your membership is paid through the ProPerX Alliance website. Your invoices and payment method are managed there.">
                    @if ($billing->paymentPortalUrl())
                        <x-ppx.button variant="primary" :href="$billing->paymentPortalUrl()" class="ppx-empty__action" target="_blank" rel="noopener">Open the payment page<span class="ppx-visually-hidden"> (opens in a new tab)</span></x-ppx.button>
                    @endif
                </x-ppx.empty-state>
            </x-ppx.card>
        @else
            @if ($unavailable)
                <p class="ppx-notice ppx-notice--warning" role="status">{{ $unavailable }}</p>
            @endif

            <div class="ppx-billing__top">
                <section class="ppx-billing-plan" aria-labelledby="ppx-billing-plan-title">
                    <div class="ppx-billing-plan__head">
                        <p class="ppx-billing-plan__eyebrow">Current plan</p>
                        <x-ppx.badge :variant="'on-navy-'.$plan['variant']">{{ $plan['status'] }}</x-ppx.badge>
                    </div>
                    <h2 class="ppx-billing-plan__title" id="ppx-billing-plan-title">{{ $plan['title'] }}</h2>
                    @if ($plan['founding'])
                        <x-ppx.badge variant="founding" :dot="false" class="ppx-billing-plan__founding">Founding rate</x-ppx.badge>
                    @endif
                    @if ($unit !== null)
                        <p class="ppx-billing-plan__price">
                            <strong>{{ Money::format((int) $unit) }}</strong>
                            <span>/ {{ $isAgency ? 'person / ' : '' }}{{ $interval }}</span>
                        </p>
                    @endif
                    <dl class="ppx-billing-plan__facts">
                        <div><dt>Next payment</dt><dd>{{ $subscription && $subscription['cancel_at_period_end'] ? 'None' : ($renewsAt?->format('j M Y') ?? '—') }}</dd></div>
                        <div><dt>Billing cycle</dt><dd>{{ $interval === 'month' ? 'Monthly' : 'Annual' }}</dd></div>
                        @if ($isAgency)
                            <div><dt>People</dt><dd>{{ $plan['quantity'] ?? '—' }}</dd></div>
                            @if ($total !== null)
                                <div><dt>Total</dt><dd>{{ Money::format((int) $total) }} / {{ $interval }}</dd></div>
                            @endif
                        @endif
                        <div><dt>Member since</dt><dd>{{ $plan['member_since']?->format('M Y') ?? '—' }}</dd></div>
                    </dl>
                </section>

                <x-ppx.card title="Payment method" heading="h2" class="ppx-billing__method">
                    <div class="ppx-billing-method">
                        @php($method = $stripe['payment_method'] ?? null)
                        <span class="ppx-billing-method__icon"><x-member.icon :name="($method['kind'] ?? 'card') === 'bank' ? 'billing' : 'card'" class="ppx-billing-method__svg" /></span>
                        <span class="ppx-billing-method__text">
                            <span class="ppx-billing-method__label">{{ $method['label'] ?? ($unavailable ? 'Not available right now' : 'No payment method on file') }}</span>
                            @if ($method['detail'] ?? null)
                                <span class="ppx-billing-method__detail">{{ $method['detail'] }}</span>
                            @endif
                        </span>
                        @if ($billing->canManage())
                            <form method="POST" action="{{ route('member.billing.portal') }}" class="ppx-billing-method__form">
                                @csrf
                                <x-ppx.button type="submit" variant="secondary" size="sm">Manage payment method</x-ppx.button>
                            </form>
                        @endif
                    </div>
                    <p class="ppx-billing-method__secure"><x-member.icon name="shield" class="ppx-billing-method__lock" /> Payments processed securely by <strong>Stripe</strong></p>
                </x-ppx.card>
            </div>

            <x-ppx.card title="Invoice history" heading="h2" class="ppx-billing__invoices">
                @php($invoices = $stripe['invoices'] ?? [])
                @if ($invoices === [])
                    <x-ppx.empty-state icon="billing" :title="$unavailable ? 'Invoices are not available right now' : 'No invoices yet'" />
                @else
                    <div class="ppx-doc-table__wrap">
                        <table class="ppx-doc-table ppx-billing-table">
                            <caption class="ppx-visually-hidden">Invoice history</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Invoice</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Status</th>
                                    <th scope="col"><span class="ppx-visually-hidden">Download</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoices as $invoice)
                                    <tr>
                                        <td data-label="Invoice"><span class="ppx-doc-table__name">{{ $invoice['number'] }}</span></td>
                                        <td data-label="Date">{{ $invoice['date']->format('j M Y') }}</td>
                                        <td data-label="Amount" class="ppx-billing-table__amount">{{ Money::exact($invoice['amount']) }}</td>
                                        <td data-label="Status"><x-ppx.badge :variant="$statusVariant[$invoice['status']] ?? 'neutral'">{{ $invoice['status'] === 'open' ? 'Due' : ucfirst($invoice['status']) }}</x-ppx.badge></td>
                                        <td class="ppx-billing-table__links">
                                            @if ($invoice['pdf'])
                                                <a class="ppx-billing-table__link" href="{{ $invoice['pdf'] }}" target="_blank" rel="noopener noreferrer"><x-member.icon name="download" class="ppx-billing-table__svg" />PDF<span class="ppx-visually-hidden"> of invoice {{ $invoice['number'] }} (opens in a new tab)</span></a>
                                            @endif
                                            @if ($invoice['url'])
                                                <a class="ppx-billing-table__link" href="{{ $invoice['url'] }}" target="_blank" rel="noopener noreferrer">{{ $invoice['status'] === 'open' ? 'Pay' : 'View' }}<span class="ppx-visually-hidden"> invoice {{ $invoice['number'] }} (opens in a new tab)</span></a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ppx.card>

            @if ($renewsAt)
                <section class="ppx-billing-renewal" aria-label="Renewal">
                    <span class="ppx-billing-renewal__icon"><x-member.icon name="calendar" class="ppx-billing-renewal__svg" /></span>
                    <span class="ppx-billing-renewal__text">
                        @if ($subscription && $subscription['cancel_at_period_end'])
                            <strong>Auto-renewal is off</strong>
                            <span>Your membership ends {{ $renewsAt->format('j M Y') }}. Contact ProPerX to continue.</span>
                        @else
                            <strong>Auto-renewal is on</strong>
                            <span>Your plan renews {{ $renewsAt->format('j M Y') }}@if ($total !== null) for {{ Money::exact((int) $total) }}@endif. Renewal is handled by ProPerX; contact us to change it.</span>
                        @endif
                    </span>
                </section>
            @endif
        @endif
    </div>
</x-member.layout>
