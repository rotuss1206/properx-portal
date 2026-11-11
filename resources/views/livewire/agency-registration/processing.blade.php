{{--
    A bank (ACH) payment of this enrollment is processing (Agency spec 6). The enrollment is locked; Stripe's
    webhook enrolls the agency when the payment clears (the principal gets an email), or opens the enrollment
    again with a link by email if it fails. App\Services\Stripe\AgencyStripeEvents.
--}}
@php($total = $payment['snapshot']['quote']['total'] ?? null)
<div>
    <div class="card status-card">
        <span class="badge status-badge">Processing</span>
        <h2>Your bank payment is processing</h2>
        <p class="lead">Bank payments (ACH) usually take up to 4 business days to clear. We'll email {{ $payment['snapshot']['principal']['email'] ?? "the principal" }} as soon as your agency is enrolled. You can close this page.</p>
        <dl class="review-list">
            @if ($total !== null)
                <dt>Amount</dt><dd>{{ \App\Support\Money::format((int) $total) }}</dd>
            @endif
            <dt>People</dt><dd>{{ $payment['snapshot']['quote']['headcount'] ?? '—' }}</dd>
            <dt>Tier</dt><dd>{{ \App\Enums\AgencyTier::tryFrom((string) ($payment['snapshot']['quote']['tier'] ?? ''))?->label() ?? '—' }}</dd>
        </dl>
        <p class="hint status-note">Your enrollment can't be changed while the payment is processing. If your bank turns it down, we'll email you a link to choose another way to pay; nothing is charged twice.</p>
    </div>
</div>
