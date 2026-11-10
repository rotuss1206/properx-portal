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

<section class="payment" aria-labelledby="payment-title">
    <h3 class="section-title" id="payment-title">Payment</h3>
    <div class="placeholder">Payment coming next: card or bank transfer (ACH), paid securely through Stripe.</div>
    @php($ready = $review->allGiven() && $quote !== null)
    <button type="button" @class(['btn', 'btn-primary', 'pay', 'is-blocked' => ! $ready]) wire:click="pay" wire:key="pay-{{ $ready ? 'ready' : 'blocked' }}"
        @if (! $ready) disabled aria-describedby="pay-hint" @else wire:loading.attr="disabled" @endif>
        Pay {{ $quote ? \App\Support\Money::format($quote->subtotal) : '' }} and enroll my agency
    </button>
    @unless ($ready)
        <p class="hint" id="pay-hint">Tick all four boxes above to continue.</p>
    @endunless
    @if ($paymentMessage)
        <p class="notice-info payment-message" id="payment-message" role="status" tabindex="-1">{{ $paymentMessage }}</p>
    @endif
</section>
