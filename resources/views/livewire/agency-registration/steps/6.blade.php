{{--
    Screen 6 · Review & payment (Agency spec 5.5): the summary with "Edit" back to each screen, the four
    required consents (each unticked to begin with; the wizard notes when each was given) and the
    payment, still a placeholder: Stripe's Payment Element comes once the client has decided on it.
--}}
@php
    $quote = $this->quote;
    $counts = $this->peopleCounts();
    $partner = $this->partnerCode;
    $address = collect([$agency->legal_address['line1'] ?? '', $agency->legal_address['line2'] ?? '', trim(($agency->legal_address['city'] ?? '').', '.($agency->legal_address['state'] ?? '').' '.($agency->legal_address['zip'] ?? ''), ', ')])->filter()->implode(', ');
    $rateKind = match (true) {
        $quote === null => null,
        $quote->partnerRateApplied => 'Partner rate',
        $quote->rateType === \App\Enums\RateType::Founding => 'Founding rate, locked while you stay a member',
        default => 'Standard rate',
    };
    $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
@endphp
<h2>Review &amp; payment</h2>
<p class="lead">Check your enrollment, accept the terms, then pay for the year.</p>

<section class="review" aria-label="Your enrollment">
    <div class="review-block">
        <div class="review-head">
            <h3 class="section-title">Membership</h3>
            <button type="button" class="link" wire:click="goTo(2)">Edit<span class="sr-only"> membership and tier</span></button>
        </div>
        <dl class="review-list">
            <dt>Membership type</dt><dd>Agency membership</dd>
            @if ($quote)
                <dt>Tier</dt><dd>{{ $quote->tier->label() }}</dd>
                <dt>Price per person</dt>
                <dd>
                    {{ \App\Support\Money::format($quote->rate) }} a year
                    <span class="review-note">{{ $rateKind }}</span>
                </dd>
            @endif
            @if ($partner)
                <dt>Partner code</dt>
                <dd>
                    {{ $partner->code }}
                    <span class="review-note">{{ $quote?->partnerRateApplied ? 'Partner rate applied' : "Your agency's rate is lower than the partner rate, so we applied it" }}</span>
                </dd>
            @endif
        </dl>
    </div>

    <div class="review-block">
        <div class="review-head">
            <h3 class="section-title">Agency</h3>
            <button type="button" class="link" wire:click="goTo(3)">Edit<span class="sr-only"> agency</span></button>
        </div>
        <dl class="review-list">
            <dt>Legal name</dt><dd>{{ $agency->legal_name }}@if ($agency->dba !== '') <span class="review-note">DBA {{ $agency->dba }}</span>@endif</dd>
            <dt>Agency type</dt><dd>{{ $agencyTypes[$agency->agency_type] ?? '—' }}</dd>
            <dt>Legal address</dt><dd>{{ $address !== '' ? $address : '—' }}</dd>
            <dt>Main phone</dt><dd>{{ $agency->phone }}</dd>
            <dt>Billing email</dt><dd>{{ $agency->billing_email }}</dd>
        </dl>
    </div>

    <div class="review-block">
        <div class="review-head">
            <h3 class="section-title">Principal</h3>
            <button type="button" class="link" wire:click="goTo(4)">Edit<span class="sr-only"> principal</span></button>
        </div>
        <dl class="review-list">
            <dt>Name</dt><dd>{{ trim($principal->first_name.' '.$principal->last_name) }}@if (isset($titles[$principal->title])) <span class="review-note">{{ $titles[$principal->title] }}</span>@endif</dd>
            <dt>Email</dt><dd>{{ $principal->email }}</dd>
            <dt>NPN</dt><dd>{{ $principal->npn }} · {{ $states[$principal->license_state] ?? $principal->license_state }}</dd>
        </dl>
    </div>

    <div class="review-block">
        <div class="review-head">
            <h3 class="section-title">People</h3>
            <button type="button" class="link" wire:click="goTo(5)">Edit<span class="sr-only"> people</span></button>
        </div>
        <p class="review-counts">{{ $plural($people->headcount(), 'person', 'people') }}: {{ $plural($counts['producers'], 'licensed producer', 'licensed producers') }} (the principal included), {{ $plural($counts['employees'], 'agency employee', 'agency employees') }}</p>
        <details class="review-people">
            <summary>Show everyone</summary>
            <ol>
                <li>{{ trim($principal->first_name.' '.$principal->last_name) }} <span class="review-note">Principal · Licensed producer</span></li>
                @foreach ($people->rows as $row)
                    <li>{{ trim($row['first_name'].' '.$row['last_name']) }} <span class="review-note">{{ $personTypes[$row['type']] ?? '' }}</span></li>
                @endforeach
            </ol>
        </details>
    </div>

    @if ($quote)
        <div class="review-total">
            <span>Total for the year</span>
            <strong>{{ \App\Support\Money::format($quote->subtotal) }}</strong>
            <span class="review-note">{{ $quote->summary() }}. Credit cards add {{ rtrim(rtrim(number_format(config('agency.credit_card_surcharge_basis_points') / 100, 2), '0'), '.') }}%; debit cards and bank transfers (ACH) do not.</span>
        </div>
    @endif
</section>

<fieldset class="consents">
    <legend class="section-title">Please confirm</legend>
    @foreach ($consents as $key => $text)
        <div class="check">
            <input id="review-{{ $key }}" type="checkbox" wire:model.live="review.{{ $key }}"
                @if ($errors->has("review.{$key}")) aria-invalid="true" aria-describedby="review-{{ $key }}-error" @endif>
            <label for="review-{{ $key }}">
                @if ($key === 'terms')
                    I agree to the <a href="{{ $termsUrl }}" target="_blank" rel="noopener">Terms of Service<span class="sr-only"> (opens in a new tab)</span></a>
                    and <a href="{{ $privacyUrl }}" target="_blank" rel="noopener">Privacy Policy<span class="sr-only"> (opens in a new tab)</span></a>.
                @else
                    {{ $text }}
                @endif
            </label>
        </div>
        <x-join.error :name="'review.'.$key" />
    @endforeach
</fieldset>

{{--
    Payment (Agency spec 6): Stripe's Payment Element (card or US bank account, public/portal/join-payment.js)
    appears once the four boxes are ticked. "Review payment" turns it into a confirmation token; the server
    reads the card's funding and shows the surcharge (credit cards only) and the total before anything is
    charged; "Pay" then creates the subscription and Stripe.js confirms it (3D Secure included).
--}}
<section class="payment" aria-labelledby="payment-title">
    <h3 class="section-title" id="payment-title">Payment</h3>
    @php($ready = $review->allGiven() && $quote !== null)

    @if (! $paymentConfigured)
        <div class="placeholder">Online payment is not set up yet. Your enrollment is saved; please contact ProPerX to complete it.</div>
    @elseif (! $ready)
        <button type="button" class="btn btn-primary pay is-blocked" disabled aria-describedby="pay-hint" wire:key="pay-blocked">
            Pay {{ $quote ? \App\Support\Money::format($quote->subtotal) : '' }} and enroll my agency
        </button>
        <p class="hint" id="pay-hint">Tick all four boxes above to choose how to pay.</p>
    @else
        <p class="hint payment-intro">Pay by card or straight from a US bank account (ACH). Credit cards add {{ rtrim(rtrim(number_format(config('agency.credit_card_surcharge_basis_points') / 100, 2), '0'), '.') }}%; you'll see the total before you pay.</p>
        <div class="payment-element" wire:ignore wire:key="payment-element"
            data-join-payment data-key="{{ $stripeKey }}" data-amount="{{ $quote->subtotal }}" data-currency="{{ config('agency.currency') }}"
            data-return-url="{{ route('join.agency') }}" data-business="{{ config('portal.brand.name', 'ProPerX Alliance') }}"></div>
        <p class="error payment-client-error" data-join-payment-error role="alert" hidden wire:ignore></p>

        @if ($checkout !== [])
            <dl class="payment-summary" id="payment-total" tabindex="-1" aria-label="Your payment">
                <dt>Paying with</dt><dd>{{ $checkout['label'] }}</dd>
                <dt>Membership dues</dt><dd>{{ \App\Support\Money::format($checkout['subtotal']) }}</dd>
                @if ($checkout['surcharge'] > 0)
                    <dt>Credit card surcharge {{ rtrim(rtrim(number_format(config('agency.credit_card_surcharge_basis_points') / 100, 2), '0'), '.') }}%</dt>
                    <dd>{{ \App\Support\Money::format($checkout['surcharge']) }}</dd>
                @else
                    <dt>Surcharge</dt><dd>None for this payment method</dd>
                @endif
                <dt class="payment-total">Total today</dt><dd class="payment-total">{{ \App\Support\Money::format($checkout['total']) }}</dd>
            </dl>
            <div class="payment-actions">
                <button type="button" class="btn btn-primary pay" data-join-pay-confirm>Pay {{ \App\Support\Money::format($checkout['total']) }} and enroll my agency</button>
                <button type="button" class="link" wire:click="clearPaymentPreview">Use another payment method</button>
            </div>
        @else
            <div class="payment-actions">
                <button type="button" class="btn btn-primary pay" data-join-pay-review>Review payment</button>
            </div>
        @endif
        <p class="hint payment-busy" data-join-payment-busy role="status" hidden wire:ignore></p>
    @endif

    @if ($paymentMessage)
        <p class="notice-info payment-message" id="payment-message" role="alert" tabindex="-1">{{ $paymentMessage }}</p>
    @endif
</section>
