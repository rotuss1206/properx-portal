@props(['prefix', 'legend', 'states', 'hint' => null])
<fieldset>
    <legend class="section-title">{{ $legend }}</legend>
    @if ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
    <x-join.input :name="$prefix.'.line1'" label="Street address" required autocomplete="address-line1" />
    <x-join.input :name="$prefix.'.line2'" label="Suite, unit or floor" autocomplete="address-line2" />
    <div class="grid grid-3">
        <x-join.input :name="$prefix.'.city'" label="City" required autocomplete="address-level2" maxlength="100" />
        <x-join.select :name="$prefix.'.state'" label="State" :options="$states" required autocomplete="address-level1" />
        <x-join.input :name="$prefix.'.zip'" label="ZIP code" required autocomplete="postal-code" inputmode="numeric" maxlength="10" />
    </div>
</fieldset>
