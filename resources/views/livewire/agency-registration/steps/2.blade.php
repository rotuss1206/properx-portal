<h2>Choose your tier</h2>
<p class="lead">Dues are per person per year, and the principal counts as one person.</p>

<fieldset aria-describedby="tier-note{{ $errors->has('tier.tier') ? ' tier-tier-error' : '' }}">
    <legend class="sr-only">Tier</legend>
    <div class="tiers">
        @foreach ($this->tierCards as $card)
            @php($size = $card['tier']->maxHeadcount() === null ? $card['tier']->minHeadcount().'+ people' : $card['tier']->minHeadcount().'–'.$card['tier']->maxHeadcount().' people')
            <label class="tier">
                <input type="radio" name="tier" wire:model.live="tier.tier" value="{{ $card['tier']->value }}">
                <span class="tier-name">{{ $card['tier']->label() }}</span>
                <span class="tier-size">{{ $size }}</span>
                <span class="tier-rate">{{ \App\Support\Money::format($card['rate']) }} <small>per person / year</small></span>
                <span><span class="badge">{{ $card['rateType'] === \App\Enums\RateType::Founding ? 'Founding rate' : 'Standard rate' }}</span></span>
            </label>
        @endforeach
    </div>
    <x-join.error name="tier.tier" />
</fieldset>

<p class="note" id="tier-note">Not sure? Pick the closest one. We'll adjust it to the number of people you add.</p>
