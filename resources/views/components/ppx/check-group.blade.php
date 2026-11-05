@props(['name', 'label', 'options' => [], 'selected' => [], 'hint' => null, 'autofocus' => false])
{{--
    A row of checkboxes aligned with x-ppx.form-row; posts name[]. A group rather than a fieldset:
    a fieldset's legend does not take part in the row's grid. Errors of the list and its items show
    under the group, and screen readers hear them with it.
--}}
@php
    $id = 'ppx-'.$name;
    $error = $errors->first($name) ?: $errors->first($name.'.*');
    $hint = $error ? null : $hint;
    $described = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="ppx-form__row">
    <span class="ppx-form__label" id="{{ $id }}-label">{{ $label }}</span>
    <div class="ppx-form__control">
        <div class="ppx-checks" role="group" aria-labelledby="{{ $id }}-label" @if ($described) aria-describedby="{{ $described }}" @endif>
            @foreach ($options as $option)
                <label class="ppx-check">
                    <input type="checkbox" name="{{ $name }}[]" value="{{ $option }}" @checked(in_array($option, $selected, true)) @if ($autofocus && $loop->first) autofocus @endif>
                    <span>{{ $option }}</span>
                </label>
            @endforeach
        </div>
        @if ($hint)
            <p class="ppx-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>
        @endif
        @if ($error)
            <p class="ppx-field__error" id="{{ $id }}-error">{{ $error }}</p>
        @endif
    </div>
</div>
