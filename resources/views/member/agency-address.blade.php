{{--
    One address of the agency, in the enrollment's fields (App\Support\AgencyAddress). A stored
    line the portal cannot read back is shown above empty fields.
--}}
@php
    $first = collect(['line1', 'line2', 'city', 'state', 'zip'])->first(fn ($field) => $errors->has("{$prefix}.{$field}"));
    $id = fn ($field) => "ppx-{$prefix}-{$field}";
    $invalid = fn ($field) => $errors->has("{$prefix}.{$field}") ? 'aria-invalid=true aria-describedby='.$id($field).'-error' : '';
@endphp
<div class="ppx-form__row" role="group" aria-labelledby="ppx-{{ $prefix }}-legend">
    <span class="ppx-form__label" id="ppx-{{ $prefix }}-legend">{{ $label }}</span>
    <div class="ppx-form__control ppx-agency__address-fields">
        @if ($stored)
            <p class="ppx-field__hint">Currently: {{ $stored }}. Enter it again in these fields to change it.</p>
        @endif
        <label class="ppx-visually-hidden" for="{{ $id('line1') }}">{{ $label }}: street address</label>
        <input class="ppx-field__input" id="{{ $id('line1') }}" name="{{ $prefix }}[line1]" value="{{ $values['line1'] ?? '' }}" placeholder="Street address" autocomplete="{{ $prefix === 'legal_address' ? 'address-line1' : 'section-mailing address-line1' }}" {{ $invalid('line1') }} @if ($first === 'line1') autofocus @endif>
        <label class="ppx-visually-hidden" for="{{ $id('line2') }}">{{ $label }}: suite, unit (optional)</label>
        <input class="ppx-field__input" id="{{ $id('line2') }}" name="{{ $prefix }}[line2]" value="{{ $values['line2'] ?? '' }}" placeholder="Suite, unit (optional)" autocomplete="{{ $prefix === 'legal_address' ? 'address-line2' : 'section-mailing address-line2' }}" {{ $invalid('line2') }}>
        <div class="ppx-agency__address">
            <span>
                <label class="ppx-visually-hidden" for="{{ $id('city') }}">{{ $label }}: city</label>
                <input class="ppx-field__input" id="{{ $id('city') }}" name="{{ $prefix }}[city]" value="{{ $values['city'] ?? '' }}" placeholder="City" autocomplete="{{ $prefix === 'legal_address' ? 'address-level2' : 'section-mailing address-level2' }}" {{ $invalid('city') }}>
            </span>
            <span>
                <label class="ppx-visually-hidden" for="{{ $id('state') }}">{{ $label }}: state</label>
                <select class="ppx-field__input" id="{{ $id('state') }}" name="{{ $prefix }}[state]" {{ $invalid('state') }}>
                    <option value="">State</option>
                    @foreach (\App\Support\UsStates::ALL as $code => $name)
                        <option value="{{ $code }}" @selected(($values['state'] ?? '') === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </span>
            <span>
                <label class="ppx-visually-hidden" for="{{ $id('zip') }}">{{ $label }}: ZIP code</label>
                <input class="ppx-field__input" id="{{ $id('zip') }}" name="{{ $prefix }}[zip]" value="{{ $values['zip'] ?? '' }}" placeholder="ZIP" inputmode="numeric" autocomplete="{{ $prefix === 'legal_address' ? 'postal-code' : 'section-mailing postal-code' }}" {{ $invalid('zip') }}>
            </span>
        </div>
        @if ($hint)
            <p class="ppx-field__hint">{{ $hint }}</p>
        @endif
        @foreach (['line1', 'line2', 'city', 'state', 'zip'] as $field)
            @error("{$prefix}.{$field}")
                <p class="ppx-field__error" id="{{ $id($field) }}-error">{{ $message }}</p>
            @enderror
        @endforeach
    </div>
</div>
