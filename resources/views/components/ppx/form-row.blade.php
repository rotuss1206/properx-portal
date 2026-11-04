@props(['name', 'label', 'type' => 'text', 'value' => null, 'options' => null, 'hint' => null, 'autofocus' => false, 'autocomplete' => null, 'required' => false, 'inputmode' => null, 'bag' => 'default'])
{{--
    One editable row of a profile section (public/portal/components.css): the label where a
    read-only row has its label, the control where it has its value. Errors come from the session
    and are tied to the control for screen readers. Types: text, tel, date, password, textarea, select.
--}}
@php
    $id = 'ppx-'.$bag.'-'.$name;
    $error = $errors->getBag($bag)->first($name);
    // The error says the same, more pointedly.
    $hint = $error ? null : $hint;
    $described = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $control = array_filter([
        'id' => $id,
        'name' => $name,
        'autocomplete' => $autocomplete,
        'inputmode' => $inputmode,
    ]);
    $flags = ($required ? ' required aria-required=true' : '').($autofocus ? ' autofocus' : '').($error ? ' aria-invalid=true' : '').($described ? ' aria-describedby="'.$described.'"' : '');
@endphp
<div class="ppx-form__row">
    <label class="ppx-form__label" for="{{ $id }}">{{ $label }}</label>
    <div class="ppx-form__control">
        @if ($type === 'textarea')
            <textarea class="ppx-field__input ppx-field__input--area" rows="3" @foreach ($control as $attribute => $content) {{ $attribute }}="{{ $content }}" @endforeach {!! $flags !!}>{{ $value }}</textarea>
        @elseif ($type === 'select')
            <select class="ppx-field__input" @foreach ($control as $attribute => $content) {{ $attribute }}="{{ $content }}" @endforeach {!! $flags !!}>
                <option value="">Choose…</option>
                @foreach ($options as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
        @else
            <input class="ppx-field__input" type="{{ $type }}" @if ($type !== 'password') value="{{ $value }}" @endif @foreach ($control as $attribute => $content) {{ $attribute }}="{{ $content }}" @endforeach {!! $flags !!}>
        @endif
        @if ($hint)
            <p class="ppx-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>
        @endif
        @if ($error)
            <p class="ppx-field__error" id="{{ $id }}-error">{{ $error }}</p>
        @endif
    </div>
</div>
