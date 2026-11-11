@props(['prefix', 'legend', 'states', 'hint' => null, 'legendId' => null])
{{-- An address; the hint (e.g. "PO boxes are not accepted") goes under the street field it is about. --}}
<fieldset class="address">
    <legend class="section-title" @if ($legendId) id="{{ $legendId }}" @endif>{{ $legend }}</legend>
    <x-join.input :name="$prefix.'.line1'" label="Street address" required autocomplete="address-line1" :hint="$hint" />
    <x-join.input :name="$prefix.'.line2'" label="Suite, unit or floor" autocomplete="address-line2" />
    <div class="grid grid-3">
        <x-join.input :name="$prefix.'.city'" label="City" required autocomplete="address-level2" maxlength="100" />
        <x-join.select :name="$prefix.'.state'" label="State" :options="$states" required autocomplete="address-level1" />
        <x-join.input :name="$prefix.'.zip'" label="ZIP code" required autocomplete="postal-code" inputmode="numeric" maxlength="10" />
    </div>
</fieldset>
