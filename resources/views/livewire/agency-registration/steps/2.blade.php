<h2>Choose your tier</h2>
<p class="lead">Dues are per person per year, and the principal counts as one person. An agency pays for at least {{ config('agency.minimum_headcount') }} members.</p>

<fieldset aria-describedby="tier-note{{ $errors->has('tier.tier') ? ' tier-tier-error' : '' }}">
    <legend class="sr-only">Tier</legend>
    <div @class(['tiers', 'tiers-two' => count($this->tierCards) === 2])>
        @foreach ($this->tierCards as $card)
            {{-- A partner card for 2 to 49 people stands for Agency and Agency Plus: it stays chosen for either. --}}
            <label class="tier" wire:key="tier-card-{{ $card['tier']->value }}">
                <input type="radio" name="tier" wire:model.live="tier.tier" value="{{ in_array($this->tier->tier, $card['tiers'], true) ? $this->tier->tier : $card['tier']->value }}">
                <span class="tier-name">{{ $card['label'] }}</span>
                <span class="tier-size">{{ $card['size'] }}</span>
                <span class="tier-rate">{{ \App\Support\Money::format($card['rate']) }} <small>per person / year</small></span>
                <span><span class="badge">{{ $card['partner'] ? 'Partner rate' : ($card['rateType'] === \App\Enums\RateType::Founding ? 'Founding rate' : 'Standard rate') }}</span></span>
            </label>
        @endforeach
    </div>
    <x-join.error name="tier.tier" />
</fieldset>

<p class="note" id="tier-note">Not sure? Pick the closest one. We'll adjust it to the number of people you add.</p>
