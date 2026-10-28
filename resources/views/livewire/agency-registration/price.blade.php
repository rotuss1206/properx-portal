@php($quote = $this->quote)
<aside class="price" aria-labelledby="price-title" aria-live="polite">
    <h2 id="price-title">Your price</h2>
    <dl>
        <dt>People</dt><dd>{{ $quote->headcount }}</dd>
        <dt>Tier</dt><dd>{{ $quote->tier->label() }}</dd>
        <dt>Rate</dt><dd>{{ \App\Support\Money::format($quote->rate) }} each</dd>
        <dt>Annual total</dt><dd class="total">{{ \App\Support\Money::format($quote->subtotal) }}</dd>
    </dl>
    @if ($quote->rateType === \App\Enums\RateType::Founding)
        <span class="badge">Founding rate (locked)</span>
    @endif
    <p class="summary">{{ $quote->summary() }}</p>
    @if ($quote->partnerCodeValid)
        <p class="notice-ok">
            {{ $quote->partnerRateApplied ? 'Partner rate applied.' : "Your agency's rate is lower than the partner rate, so we applied it." }}
        </p>
    @endif
    <p class="estimate">Estimate based on the tier you picked. It updates when you add your people.</p>
</aside>
