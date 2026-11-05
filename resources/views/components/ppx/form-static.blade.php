@props(['label'])
{{-- A read-only row inside a form, aligned with x-ppx.form-row. --}}
<div class="ppx-form__row">
    <span class="ppx-form__label">{{ $label }}</span>
    <div class="ppx-form__control ppx-form__static">{{ $slot }}</div>
</div>
